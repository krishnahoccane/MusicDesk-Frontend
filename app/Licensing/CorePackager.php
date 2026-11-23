<?php

namespace App\Licensing;

use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

/**
 * LabelDesk release packages for over-the-air updates (LabelDesk 4.3.3+). Same format LabelDesk
 * installs (App\Services\Updates\CorePackage there):
 *
 *   labeldesk-<version>/signature.json   {"alg":"Ed25519","kid","signed":"<base64 JSON>","signature":"<base64>"}
 *   labeldesk-<version>/app/…, vendor/…  every other file, listed with its SHA-256 in the signed JSON
 *
 * signed JSON = {"product":"labeldesk","version","files":{"<path>":"<sha256>"}}, signed with the
 * plugin signing key (same key as add-on packages, config/plugins.php).
 *
 * prepare() accepts a package already signed (scripts/package-release.php --key=…), verified against
 * the public key LabelDesk ships with, or an unsigned release ZIP, which is signed here.
 */
class CorePackager
{
    public const MIN_FILES = 10;

    public const MAX_FILES = 80000;

    public const MAX_BYTES = 600 * 1024 * 1024;

    /** Never shipped in a release: secrets, logs, backups. */
    private const FORBIDDEN = [
        '#(^|/)\.env(\.(?!example$)[^/]+)?$#' => 'a .env file',
        '#^storage/logs/(?!\.gitignore$).+#' => 'log files (storage/logs)',
        // Backups live under storage/ only; LabelDesk's own code has Backup/Backups folders (app/Livewire/Backups, …).
        '#^storage/(.+/)?backups?/(?!\.gitignore$).+#i' => 'backups',
        '#\.(sql|sql\.gz|sqlite|bak|dump)$#i' => 'a database dump or backup file',
    ];

    /**
     * Check an uploaded LabelDesk ZIP for the given version; sign it when it isn't signed yet.
     *
     * @return array{path: string, signed_here: bool} path = the file to store (a temp file when signed here)
     *
     * @throws RuntimeException with a message for the admin
     */
    public function prepare(string $zipPath, string $version): array
    {
        $folder = 'labeldesk-'.$version;
        $zip = $this->open($zipPath);
        try {
            [$root, $entries] = $this->inspect($zip);
            if ($root !== '' && $root !== $folder) {
                throw new RuntimeException("The folder in the ZIP is “{$root}”; it must be “{$folder}” for version {$version}. Rename it, or zip the files without a top folder.");
            }

            if (in_array('signature.json', $entries, true)) {
                if ($root === '') {
                    throw new RuntimeException("A signed package must contain the folder {$folder}/.");
                }
                $this->verify($zip, $root, $version);

                return ['path' => $zipPath, 'signed_here' => false];
            }

            $out = $this->sign($zip, $root, $entries, $version);
        } finally {
            $zip->close();
        }

        return ['path' => $out, 'signed_here' => true];
    }

    /** Open a finished package and verify it exactly like LabelDesk does. */
    public function verifyFile(string $zipPath, string $version): void
    {
        $zip = $this->open($zipPath);
        try {
            [$root] = $this->inspect($zip);
            if ($root !== 'labeldesk-'.$version) {
                throw new RuntimeException('The package must contain a single folder named labeldesk-'.$version.'.');
            }
            $this->verify($zip, $root, $version);
        } finally {
            $zip->close();
        }
    }

    // ------------------------------------------------------------ internals

    private function open(string $path): ZipArchive
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('The file is not a valid ZIP archive.');
        }

        return $zip;
    }

    /**
     * Safety checks (as LabelDesk's CorePackage::open, plus the forbidden files). Returns the top
     * folder ('' when the files sit at the ZIP root) and the file paths relative to it.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function inspect(ZipArchive $zip): array
    {
        if ($zip->numFiles < self::MIN_FILES || $zip->numFiles > self::MAX_FILES) {
            throw new RuntimeException('The LabelDesk package has an unexpected number of files.');
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
            throw new RuntimeException('The LabelDesk package is too large (600 MB at most).');
        }

        $files = array_values(array_filter($names, fn ($n) => ! str_ends_with($n, '/')));
        $tops = array_unique(array_map(fn ($n) => explode('/', $n, 2)[0], $names));
        // One top folder (its name is checked by prepare()), or the files straight at the root.
        $root = count($tops) === 1 && ! in_array((string) reset($tops), $files, true) ? (string) reset($tops) : '';
        $relative = array_values(array_filter(array_map(fn ($n) => $root === '' ? $n : substr($n, strlen($root) + 1), $files), fn ($r) => $r !== ''));

        foreach ($relative as $path) {
            foreach (self::FORBIDDEN as $pattern => $what) {
                if (preg_match($pattern, $path)) {
                    throw new RuntimeException("The package contains {$what} ({$path}). Build it again without it.");
                }
            }
        }

        return [$root, $relative];
    }

    private function verify(ZipArchive $zip, string $root, string $version): void
    {
        $raw = $zip->getFromName($root.'/signature.json');
        $signature = $raw === false ? null : json_decode($raw, true);
        if (! is_array($signature) || ($signature['alg'] ?? null) !== 'Ed25519' || ! is_string($signature['signed'] ?? null) || ! is_string($signature['signature'] ?? null)) {
            throw new RuntimeException('signature.json is not a MusicDesk signature.');
        }
        $signed = base64_decode($signature['signed'], true);
        $sig = base64_decode($signature['signature'], true);
        if ($signed === false || $sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES || ! sodium_crypto_sign_verify_detached($sig, $signed, PluginKeys::publicKey())) {
            throw new RuntimeException('The package signature is not valid for the MusicDesk plugin key. Build it again with scripts/package-release.php --key=<plugin-signing.key>, or upload it unsigned to have it signed here.');
        }

        $claims = json_decode($signed, true);
        if (! is_array($claims) || ($claims['product'] ?? null) !== 'labeldesk' || ($claims['version'] ?? null) !== $version || ! is_array($claims['files'] ?? null)) {
            throw new RuntimeException("The signature is not for LabelDesk {$version}.");
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
     * Repackage as labeldesk-<version>/… with a signature.json, in a temp file.
     *
     * @param  list<string>  $entries
     */
    private function sign(ZipArchive $zip, string $root, array $entries, string $version): string
    {
        $secret = PluginKeys::secretKey('This LabelDesk package is not signed and the plugin signing key is not installed on this server. Upload a ZIP built with LabelDesk\'s scripts/package-release.php --key=….');
        $prefix = $root === '' ? '' : $root.'/';
        $folder = 'labeldesk-'.$version;

        $tmpDir = storage_path('app/private/release-build-'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($tmpDir);
        try {
            $files = [];
            foreach ($entries as $relative) {
                $target = $tmpDir.'/'.$relative;
                File::ensureDirectoryExists(dirname($target));
                $in = $zip->getStream($prefix.$relative);
                $out = fopen($target, 'wb');
                stream_copy_to_stream($in, $out);
                fclose($in);
                fclose($out);
                $files[$relative] = hash_file('sha256', $target);
            }
            ksort($files, SORT_STRING);

            $signed = (string) json_encode(['product' => 'labeldesk', 'version' => $version, 'files' => $files], JSON_UNESCAPED_SLASHES);
            $signature = json_encode([
                'alg' => 'Ed25519',
                'kid' => PluginKeys::kid(),
                'signed' => base64_encode($signed),
                'signature' => base64_encode(sodium_crypto_sign_detached($signed, $secret)),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            sodium_memzero($secret);

            $outZip = $tmpDir.'.zip';
            $new = new ZipArchive;
            if ($new->open($outZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not create the signed package.');
            }
            $new->addEmptyDir($folder);
            foreach (array_keys($files) as $relative) {
                $new->addFile($tmpDir.'/'.$relative, $folder.'/'.$relative);
            }
            $new->addFromString($folder.'/signature.json', (string) $signature);
            $new->close();
        } finally {
            File::deleteDirectory($tmpDir);
        }

        $this->verifyFile($outZip, $version);   // prove LabelDesk will accept it

        return $outZip;
    }
}
