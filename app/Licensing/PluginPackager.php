<?php

namespace App\Licensing;

use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

/**
 * Add-on (plugin) packages for LabelDesk. Same format LabelDesk installs (App\Plugins\PluginPackage there):
 *
 *   <slug>/plugin.json       {"slug","name","version","addon","namespace","provider",…}
 *   <slug>/signature.json    {"alg":"Ed25519","kid","signed":"<base64 JSON>","signature":"<base64>"}
 *   <slug>/…                 every other file, listed with its SHA-256 in the signed JSON
 *
 * signed JSON = {"slug","version","addon","files":{"<path>":"<sha256>"}}.
 *
 * prepare() accepts either a package that is already signed (verified against the public key
 * LabelDesk ships with) or an unsigned plugin folder zipped up (with or without the top folder),
 * which is signed here with the plugin signing key file.
 */
class PluginPackager
{
    public const MAX_FILES = 5000;

    public const MAX_BYTES = 200 * 1024 * 1024;

    /** Same exclusions as LabelDesk's scripts/build-plugin.php. */
    private const EXCLUDE = '#(^|/)(\.git|node_modules|tests|__MACOSX)(/|$)|(^|/)\.DS_Store$#';

    /**
     * Check an uploaded ZIP for the given add-on and version; sign it when it isn't signed yet.
     *
     * @return array{path: string, manifest: array<string, mixed>, signed_here: bool} path = the file to store (a temp file when signed here)
     *
     * @throws RuntimeException with a message for the admin
     */
    public function prepare(string $zipPath, string $addonCode, string $version): array
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('The file is not a valid ZIP archive.');
        }

        try {
            [$root, $entries] = $this->inspect($zip);
            $json = $zip->getFromName(($root === '' ? '' : $root.'/').'plugin.json');
            $manifest = $this->manifest($json === false ? '' : $json);
            if ($manifest['addon'] !== $addonCode) {
                throw new RuntimeException("plugin.json is for the \"{$manifest['addon']}\" add-on, not \"{$addonCode}\". Pick the matching product.");
            }
            if ($manifest['version'] !== $version) {
                throw new RuntimeException("plugin.json says version {$manifest['version']}; this release is {$version}.");
            }
            if ($root !== '' && $root !== $manifest['slug']) {
                throw new RuntimeException("The folder in the ZIP ({$root}) must be named after the plugin slug ({$manifest['slug']}).");
            }

            if (in_array('signature.json', $entries, true)) {
                if ($root === '') {
                    throw new RuntimeException('A signed package must contain the plugin folder ('.$manifest['slug'].'/…).');
                }
                $this->verify($zip, $root, $manifest, $this->publicKey());

                return ['path' => $zipPath, 'manifest' => $manifest, 'signed_here' => false];
            }

            $out = $this->sign($zip, $root, $entries, $manifest);
        } finally {
            $zip->close();
        }

        return ['path' => $out, 'manifest' => $manifest, 'signed_here' => true];
    }

    /** Open a finished package and verify it exactly like LabelDesk does. Returns the manifest. */
    public function verifyFile(string $zipPath): array
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('The file is not a valid ZIP archive.');
        }
        try {
            [$root] = $this->inspect($zip);
            $json = $zip->getFromName($root.'/plugin.json');
            $manifest = $this->manifest($json === false ? '' : $json);
            if ($root === '' || $root !== $manifest['slug']) {
                throw new RuntimeException('The package must contain a single folder named after the plugin slug.');
            }
            $this->verify($zip, $root, $manifest, $this->publicKey());

            return $manifest;
        } finally {
            $zip->close();
        }
    }

    public function canSign(): bool
    {
        return is_file((string) config('plugins.signing_key_path'));
    }

    // ------------------------------------------------------------ internals

    /**
     * Safety checks; returns the top folder ('' when files sit at the ZIP root) and the
     * file paths relative to it.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function inspect(ZipArchive $zip): array
    {
        if ($zip->numFiles < 2 || $zip->numFiles > self::MAX_FILES) {
            throw new RuntimeException('The add-on package has an unexpected number of files.');
        }

        $names = [];
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = (string) $stat['name'];
            if ($name === '' || str_contains($name, '\\') || str_starts_with($name, '/') || preg_match('#(^|/)\.\.(/|$)#', $name)
                || preg_match('/[\x00-\x1F:]/', $name) || strlen($name) > 400) {
                throw new RuntimeException('The package contains an unsafe file name.');
            }
            if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000) {
                throw new RuntimeException('The package contains a symbolic link, which is not allowed.');
            }
            $total += (int) $stat['size'];
            $names[] = $name;
        }
        if ($total > self::MAX_BYTES) {
            throw new RuntimeException('The add-on package is too large (200 MB at most).');
        }

        $files = array_values(array_filter($names, fn ($n) => ! str_ends_with($n, '/')));
        $tops = array_unique(array_map(fn ($n) => explode('/', $n, 2)[0], $names));
        $root = count($tops) === 1 && ! in_array('plugin.json', $files, true) ? (string) reset($tops) : '';
        $relative = array_map(fn ($n) => $root === '' ? $n : substr($n, strlen($root) + 1), $files);

        return [$root, array_values(array_filter($relative, fn ($r) => $r !== ''))];
    }

    /** @return array<string, mixed> */
    private function manifest(string $json): array
    {
        if ($json === '') {
            throw new RuntimeException('This is not a LabelDesk add-on: plugin.json is missing.');
        }
        $data = json_decode($json, true);
        if (! is_array($data)) {
            throw new RuntimeException('plugin.json is not valid JSON.');
        }
        $checks = [
            'slug' => '/^[a-z0-9][a-z0-9-]{1,58}[a-z0-9]$/',
            'version' => '/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/',
            'addon' => '/^[a-z0-9_]{2,40}$/',
            'namespace' => '/^LabelDesk\\\\Addons\\\\[A-Z][A-Za-z0-9]*\\\\$/',
            'provider' => '/^LabelDesk\\\\Addons\\\\[A-Z][A-Za-z0-9]*\\\\[A-Z][A-Za-z0-9\\\\]*$/',
        ];
        foreach ($checks as $key => $pattern) {
            if (! is_string($data[$key] ?? null) || ! preg_match($pattern, $data[$key])) {
                throw new RuntimeException("plugin.json has a missing or invalid \"{$key}\".");
            }
        }
        if (! is_string($data['name'] ?? null) || trim($data['name']) === '') {
            throw new RuntimeException('plugin.json has a missing or invalid "name".');
        }
        if (! str_starts_with($data['provider'], $data['namespace'])) {
            throw new RuntimeException('The plugin provider must be inside the plugin namespace.');
        }

        return $data;
    }

    /** @param array<string, mixed> $manifest */
    private function verify(ZipArchive $zip, string $root, array $manifest, string $publicKey): void
    {
        $raw = $zip->getFromName($root.'/signature.json');
        $signature = $raw === false ? null : json_decode($raw, true);
        if (! is_array($signature) || ($signature['alg'] ?? null) !== 'Ed25519' || ! is_string($signature['signed'] ?? null) || ! is_string($signature['signature'] ?? null)) {
            throw new RuntimeException('signature.json is not a MusicDesk plugin signature.');
        }
        $signed = base64_decode($signature['signed'], true);
        $sig = base64_decode($signature['signature'], true);
        if ($signed === false || $sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES || ! sodium_crypto_sign_verify_detached($sig, $signed, $publicKey)) {
            throw new RuntimeException('The package signature is not valid for the MusicDesk plugin key. Build it again with scripts/build-plugin.php and the MusicDesk plugin-signing.key, or upload it unsigned to have it signed here.');
        }

        $claims = json_decode($signed, true);
        if (! is_array($claims) || ($claims['slug'] ?? null) !== $manifest['slug'] || ($claims['version'] ?? null) !== $manifest['version']
            || ($claims['addon'] ?? null) !== $manifest['addon'] || ! is_array($claims['files'] ?? null)) {
            throw new RuntimeException('The signature does not match plugin.json.');
        }

        $expected = $claims['files'];
        $seen = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_ends_with($name, '/')) {
                continue;
            }
            $relative = substr($name, strlen($root) + 1);
            if ($relative === 'signature.json') {
                continue;
            }
            if (! isset($expected[$relative])) {
                throw new RuntimeException("The package contains a file that was not signed: {$relative}");
            }
            $stream = $zip->getStream($name);
            $ctx = hash_init('sha256');
            hash_update_stream($ctx, $stream);
            fclose($stream);
            if (! hash_equals((string) $expected[$relative], hash_final($ctx))) {
                throw new RuntimeException("The file {$relative} was changed after signing.");
            }
            $seen++;
        }
        if ($seen !== count($expected)) {
            throw new RuntimeException('The package is incomplete: signed files are missing.');
        }
    }

    /**
     * Repackage as <slug>/… with a signature.json, in a temp file.
     *
     * @param  list<string>  $entries
     * @param  array<string, mixed>  $manifest
     */
    private function sign(ZipArchive $zip, string $root, array $entries, array $manifest): string
    {
        $secret = $this->secretKey();
        $prefix = $root === '' ? '' : $root.'/';
        $slug = $manifest['slug'];

        $tmpDir = storage_path('app/private/plugin-build-'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($tmpDir);
        try {
            $files = [];
            foreach ($entries as $relative) {
                if (preg_match(self::EXCLUDE, $relative)) {
                    continue;
                }
                $target = $tmpDir.'/'.$relative;
                File::ensureDirectoryExists(dirname($target));
                $in = $zip->getStream($prefix.$relative);
                $out = fopen($target, 'wb');
                stream_copy_to_stream($in, $out);
                fclose($in);
                fclose($out);
                $files[$relative] = hash_file('sha256', $target);
            }
            ksort($files);

            $signed = json_encode(['slug' => $slug, 'version' => $manifest['version'], 'addon' => $manifest['addon'], 'files' => $files], JSON_UNESCAPED_SLASHES);
            $signature = json_encode([
                'alg' => 'Ed25519',
                'kid' => substr(hash('sha256', sodium_crypto_sign_publickey_from_secretkey($secret)), 0, 16),
                'signed' => base64_encode($signed),
                'signature' => base64_encode(sodium_crypto_sign_detached($signed, $secret)),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            sodium_memzero($secret);

            $outZip = $tmpDir.'.zip';
            $new = new ZipArchive;
            if ($new->open($outZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not create the signed package.');
            }
            $new->addEmptyDir($slug);
            foreach (array_keys($files) as $relative) {
                $new->addFile($tmpDir.'/'.$relative, $slug.'/'.$relative);
            }
            $new->addFromString($slug.'/signature.json', (string) $signature);
            $new->close();
        } finally {
            File::deleteDirectory($tmpDir);
        }

        $this->verifyFile($outZip);   // prove LabelDesk will accept it

        return $outZip;
    }

    private function secretKey(): string
    {
        $path = (string) config('plugins.signing_key_path');
        if (! is_file($path)) {
            throw new RuntimeException('This package is not signed and the plugin signing key is not installed on this server. Upload a ZIP built with LabelDesk\'s scripts/build-plugin.php.');
        }
        $secret = base64_decode(trim((string) file_get_contents($path)), true);
        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('The plugin signing key file is not a base64 Ed25519 secret key.');
        }
        if (! hash_equals($this->publicKey(), sodium_crypto_sign_publickey_from_secretkey($secret))) {
            throw new RuntimeException('The plugin signing key does not match the public key LabelDesk trusts (config plugins.public_key).');
        }

        return $secret;
    }

    private function publicKey(): string
    {
        $key = base64_decode((string) config('plugins.public_key'), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new RuntimeException('config plugins.public_key is not a base64 Ed25519 public key.');
        }

        return $key;
    }
}
