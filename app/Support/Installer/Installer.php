<?php

namespace App\Support\Installer;

use Illuminate\Encryption\Encrypter;

/**
 * Web installer state. The app counts as installed once storage/app/installed.json exists
 * (written after the super admin is created), or when APP_INSTALLED is set explicitly.
 */
class Installer
{
    public const STEPS = [
        'requirements' => ['Prerequisites', 'Check the server can run the app'],
        'database' => ['Database', 'Connect and create the tables'],
        'redis' => ['Redis', 'Optional cache, sessions and queue'],
        'mail' => ['Email (SMTP)', 'Send invoices and account emails'],
        'admin' => ['Super admin', 'Create the first administrator'],
    ];

    public static function isInstalled(): bool
    {
        $forced = config('app.installed');
        if ($forced !== null && $forced !== '') {
            return filter_var($forced, FILTER_VALIDATE_BOOL);
        }

        return is_file(self::markerPath());
    }

    public static function markerPath(): string
    {
        return storage_path('app/installed.json');
    }

    public static function progressPath(): string
    {
        return storage_path('app/install-progress.json');
    }

    public static function markInstalled(array $meta = []): void
    {
        file_put_contents(self::markerPath(), json_encode(['installed_at' => now()->toIso8601String()] + $meta, JSON_PRETTY_PRINT));
        @unlink(self::progressPath());
    }

    /** @return array<string, string> step => 'done' | 'skipped' */
    public static function progress(): array
    {
        $data = is_file(self::progressPath()) ? json_decode((string) file_get_contents(self::progressPath()), true) : null;

        return is_array($data) ? $data : [];
    }

    public static function complete(string $step, string $state = 'done'): void
    {
        $p = self::progress();
        $p[$step] = $state;
        file_put_contents(self::progressPath(), json_encode($p, JSON_PRETTY_PRINT));
    }

    /** The first step that isn't finished yet. */
    public static function currentStep(): string
    {
        $done = self::progress();
        foreach (array_keys(self::STEPS) as $step) {
            if (! isset($done[$step])) {
                return $step;
            }
        }

        return 'admin';
    }

    /** May the visitor open this step? (Every earlier step must be finished.) */
    public static function canOpen(string $step): bool
    {
        $done = self::progress();
        foreach (array_keys(self::STEPS) as $s) {
            if ($s === $step) {
                return true;
            }
            if (! isset($done[$s])) {
                return false;
            }
        }

        return false;
    }

    /**
     * Runs before any request while the app isn't installed: makes sure .env and APP_KEY exist
     * (cookies and sessions need the key) and keeps sessions/cache on files until a database exists.
     */
    public static function prepareRuntime(): void
    {
        $env = app(EnvWriter::class);
        try {
            $env->ensureExists();
            if (blank(config('app.key')) && blank($env->get('APP_KEY'))) {
                $key = 'base64:'.base64_encode(Encrypter::generateKey(config('app.cipher')));
                $env->set(['APP_KEY' => $key]);
                config(['app.key' => $key]);
            } elseif (blank(config('app.key'))) {
                config(['app.key' => $env->get('APP_KEY')]);
            }
        } catch (\Throwable) {
            // Not writable yet: the prerequisites step reports it. Use a temporary key for this request.
            if (blank(config('app.key'))) {
                config(['app.key' => 'base64:'.base64_encode(Encrypter::generateKey(config('app.cipher')))]);
            }
        }

        config([
            'session.driver' => 'file',
            'cache.default' => 'file',
            'queue.default' => 'sync',
        ]);
    }
}
