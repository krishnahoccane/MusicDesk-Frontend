<?php

namespace App\Support\Installer;

/** Step 1: what the server needs to run musicdesk.tech. */
class Requirements
{
    public const MIN_PHP = '8.3.0';

    /** Required PHP extensions: [extension => why]. */
    public const EXTENSIONS = [
        'bcmath' => 'Money and currency maths',
        'ctype' => 'Laravel core',
        'curl' => 'Payment gateways, exchange rates, geo-IP',
        'dom' => 'Invoice PDFs',
        'fileinfo' => 'Logo and image uploads',
        'filter' => 'Laravel core',
        'hash' => 'Webhook signatures',
        'mbstring' => 'Text handling',
        'openssl' => 'Encryption and HTTPS',
        'pdo' => 'Database access',
        'session' => 'Sign-in sessions',
        'tokenizer' => 'Laravel core',
        'xml' => 'Laravel core',
    ];

    /** Recommended (the app works without them, with limits). */
    public const RECOMMENDED = [
        'gd' => 'Images in invoice PDFs',
        'intl' => 'Better number and date formatting',
        'zip' => 'Downloading invoice archives',
        'redis' => 'Fastest Redis client (the bundled predis client also works)',
    ];

    /** @return array<string, array<int, array{label: string, detail: string, ok: bool, required: bool, hint: ?string}>> */
    public static function check(): array
    {
        $groups = [];

        $groups['Server'] = [
            self::item('PHP '.self::MIN_PHP.' or newer', 'Running PHP '.PHP_VERSION, version_compare(PHP_VERSION, self::MIN_PHP, '>='), true,
                'Ask your host to switch this site to PHP 8.3 or 8.4.'),
            self::item('Composer dependencies', 'vendor/ folder', is_file(base_path('vendor/autoload.php')), true, 'Run: composer install --no-dev --optimize-autoloader'),
            self::item('Front-end assets built', 'public/build/manifest.json', is_file(public_path('build/manifest.json')), true, 'Run: npm ci && npm run build (or upload the public/build folder).'),
        ];

        foreach (self::EXTENSIONS as $ext => $why) {
            $groups['PHP extensions'][] = self::item($ext, $why, extension_loaded($ext), true, "Enable the PHP “{$ext}” extension.");
        }
        foreach (self::RECOMMENDED as $ext => $why) {
            $groups['PHP extensions'][] = self::item($ext, $why, extension_loaded($ext), false, null);
        }

        $drivers = array_values(array_intersect(['mysql', 'pgsql', 'sqlite'], class_exists(\PDO::class) ? \PDO::getAvailableDrivers() : []));
        $groups['Database driver'] = [
            self::item('At least one PDO driver', $drivers ? 'Available: '.implode(', ', $drivers) : 'None found', $drivers !== [], true,
                'Enable pdo_mysql (MySQL/MariaDB), pdo_pgsql (PostgreSQL) or pdo_sqlite.'),
        ];

        $env = base_path('.env');
        $groups['Folder permissions'] = [
            self::item('.env file writable', is_file($env) ? '.env' : '.env (will be created)', is_file($env) ? is_writable($env) : is_writable(base_path()), true,
                'Make .env (or the application folder) writable by the web server user.'),
        ];
        foreach (['storage', 'storage/app', 'storage/framework', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache'] as $dir) {
            $groups['Folder permissions'][] = self::item($dir, 'must be writable', is_dir(base_path($dir)) && is_writable(base_path($dir)), true,
                "chmod -R 775 {$dir} and make it owned by the web server user.");
        }

        return $groups;
    }

    public static function passes(): bool
    {
        foreach (self::check() as $items) {
            foreach ($items as $item) {
                if ($item['required'] && ! $item['ok']) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function item(string $label, string $detail, bool $ok, bool $required, ?string $hint): array
    {
        return compact('label', 'detail', 'ok', 'required', 'hint');
    }
}
