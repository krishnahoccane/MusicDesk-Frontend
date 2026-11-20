<?php

namespace App\Support\Installer;

use RuntimeException;

/**
 * Reads and updates the .env file in place: existing keys are replaced on their own line,
 * new keys are appended, comments and ordering are kept. Writes atomically.
 */
class EnvWriter
{
    public function __construct(private ?string $path = null)
    {
        $this->path ??= base_path('.env');
    }

    public function path(): string
    {
        return $this->path;
    }

    /** Create .env from .env.example if it doesn't exist yet. */
    public function ensureExists(): void
    {
        if (is_file($this->path)) {
            return;
        }
        $example = dirname($this->path).DIRECTORY_SEPARATOR.'.env.example';
        if (! @copy($example, $this->path) && ! @file_put_contents($this->path, '')) {
            throw new RuntimeException('Could not create .env. Make the application folder writable.');
        }
    }

    public function get(string $key): ?string
    {
        $contents = is_file($this->path) ? (string) file_get_contents($this->path) : '';
        if (! preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $contents, $m)) {
            return null;
        }
        $value = trim($m[1]);
        if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
            $value = stripcslashes(substr($value, 1, -1));
        } elseif (strlen($value) >= 2 && $value[0] === "'" && str_ends_with($value, "'")) {
            $value = substr($value, 1, -1);
        }

        return $value === 'null' ? null : $value;
    }

    /** @param  array<string, string|int|bool|null>  $values */
    public function set(array $values): void
    {
        $this->ensureExists();
        $contents = (string) file_get_contents($this->path);

        foreach ($values as $key => $value) {
            if (! preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                throw new RuntimeException("Invalid .env key [$key].");
            }
            $line = $key.'='.self::format($value);
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
            $contents = preg_match($pattern, $contents)
                ? preg_replace_callback($pattern, fn () => $line, $contents)
                : rtrim($contents, "\r\n").PHP_EOL.$line.PHP_EOL;
        }

        $tmp = $this->path.'.tmp-'.bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $contents) === false || ! @rename($tmp, $this->path)) {
            @unlink($tmp);
            // rename() can fail on Windows when the target is locked; fall back to a direct write.
            if (file_put_contents($this->path, $contents) === false) {
                throw new RuntimeException('Could not write .env. Make it writable by the web server.');
            }
        }
    }

    public static function format(string|int|bool|null $value): string
    {
        if ($value === null || $value === '') {
            return $value === null ? 'null' : '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        $value = (string) $value;
        if (preg_match('/^[A-Za-z0-9_.:\/@\-+]+$/', $value) && ! in_array(strtolower($value), ['true', 'false', 'null', 'empty'], true)) {
            return $value;
        }

        // Quote anything with spaces, #, quotes, $ or other specials. Escape \ " and $ (no variable expansion).
        return '"'.str_replace(['\\', '"', '$', "\n", "\r"], ['\\\\', '\\"', '\\$', '\\n', ''], $value).'"';
    }
}
