<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Installer\EnvWriter;
use App\Support\Installer\Installer;
use App\Support\Installer\Requirements;
use App\Support\Settings;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/**
 * Web installer at /install:
 *   1 prerequisites → 2 database → 3 Redis (optional) → 4 SMTP → 5 super admin.
 * Each step writes .env, so it works on hosts without shell access.
 */
class InstallController extends Controller
{
    public function __construct(private EnvWriter $env) {}

    public function index(): RedirectResponse
    {
        return redirect()->route('install.step', Installer::currentStep());
    }

    public function show(Request $request, string $step): View|RedirectResponse
    {
        abort_unless(isset(Installer::STEPS[$step]), 404);
        if (! Installer::canOpen($step)) {
            return redirect()->route('install.step', Installer::currentStep());
        }

        return view("install.$step", [
            'step' => $step,
            'steps' => Installer::STEPS,
            'progress' => Installer::progress(),
            'env' => $this->env,
        ] + $this->stepData($request, $step));
    }

    private function stepData(Request $request, string $step): array
    {
        return match ($step) {
            'requirements' => ['groups' => Requirements::check(), 'passes' => Requirements::passes()],
            'database' => ['drivers' => array_values(array_intersect(['mysql', 'pgsql', 'sqlite'], \PDO::getAvailableDrivers()))],
            'redis' => ['client' => $this->redisClient()],
            'admin' => ['detectedUrl' => rtrim($request->root(), '/')],
            default => [],
        };
    }

    // ------------------------------------------------------------- Step 1

    public function requirements(): RedirectResponse
    {
        if (! Requirements::passes()) {
            return back()->withErrors(['requirements' => 'Fix the items marked in red, then check again.']);
        }
        Installer::complete('requirements');

        return redirect()->route('install.step', 'database');
    }

    // ------------------------------------------------------------- Step 2

    public function database(Request $request): RedirectResponse
    {
        $this->guard('database');
        $sqlite = $request->input('driver') === 'sqlite';
        $data = $request->validate([
            'driver' => ['required', Rule::in(['mysql', 'mariadb', 'pgsql', 'sqlite'])],
            'host' => [$sqlite ? 'nullable' : 'required', 'string', 'max:255'],
            'port' => [$sqlite ? 'nullable' : 'required', 'integer', 'between:1,65535'],
            'database' => [$sqlite ? 'nullable' : 'required', 'string', 'max:255'],
            'username' => [$sqlite ? 'nullable' : 'required', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'path' => [$sqlite ? 'required' : 'nullable', 'string', 'max:500'],
        ]) + ['password' => null];

        $driver = $data['driver'];
        $connection = config("database.connections.$driver");
        if ($sqlite) {
            $path = $this->absolutePath($data['path']);
            if (! is_file($path) && ! @touch($path)) {
                return back()->withInput()->withErrors(['path' => "Can’t create the SQLite file at $path. Make its folder writable."]);
            }
            $connection = array_merge($connection, ['database' => $path]);
            $envValues = ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $path, 'DB_HOST' => null, 'DB_PORT' => null, 'DB_USERNAME' => null, 'DB_PASSWORD' => null];
        } else {
            $connection = array_merge($connection, [
                'host' => $data['host'], 'port' => (int) $data['port'], 'database' => $data['database'],
                'username' => $data['username'], 'password' => (string) ($data['password'] ?? ''),
            ]);
            $envValues = ['DB_CONNECTION' => $driver, 'DB_HOST' => $data['host'], 'DB_PORT' => (int) $data['port'],
                'DB_DATABASE' => $data['database'], 'DB_USERNAME' => $data['username'], 'DB_PASSWORD' => (string) ($data['password'] ?? '')];
        }

        // 1. Can we connect?
        config(['database.connections.installer' => $connection]);
        DB::purge('installer');
        try {
            DB::connection('installer')->getPdo();
        } catch (\Throwable $e) {
            return back()->withInput($request->except('password'))->withErrors(['connection' => 'Could not connect: '.$this->clean($e->getMessage())]);
        }

        // 2. Save, then create the tables and reference data on that connection.
        $this->env->set($envValues);
        config(["database.connections.$driver" => $connection, 'database.default' => $driver]);
        DB::purge($driver);
        try {
            Artisan::call('migrate', ['--force' => true]);
            Artisan::call('db:seed', ['--class' => ReferenceDataSeeder::class, '--force' => true]);
        } catch (\Throwable $e) {
            return back()->withInput($request->except('password'))->withErrors(['connection' => 'Connected, but setting up the tables failed: '.$this->clean($e->getMessage())]);
        }

        Installer::complete('database');
        $this->clearConfigCache();

        return redirect()->route('install.step', 'redis')->with('status', 'Database connected and tables created.');
    }

    // ------------------------------------------------------------- Step 3 (optional)

    public function redis(Request $request): RedirectResponse
    {
        $this->guard('redis');

        if ($request->input('action') === 'skip') {
            $this->env->set(['CACHE_STORE' => 'database', 'SESSION_DRIVER' => 'database', 'QUEUE_CONNECTION' => 'database']);
            Installer::complete('redis', 'skipped');
            $this->clearConfigCache();

            return redirect()->route('install.step', 'mail')->with('status', 'Redis skipped. Cache, sessions and the queue use the database.');
        }

        $data = $request->validate([
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'password' => ['nullable', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:255'],
            'db' => ['required', 'integer', 'between:0,15'],
            'use' => ['array'], 'use.*' => [Rule::in(['cache', 'session', 'queue'])],
        ]) + ['password' => null, 'username' => null, 'use' => []];

        $client = $this->redisClient();
        if (! $client) {
            return back()->withInput()->withErrors(['redis' => 'No Redis client is available. Enable the PHP “redis” extension or run: composer require predis/predis. Or skip this step.']);
        }

        config([
            'database.redis.client' => $client,
            'database.redis.installer' => array_filter([
                'host' => $data['host'], 'port' => (int) $data['port'], 'database' => (int) $data['db'],
                'password' => $data['password'] ?: null, 'username' => $data['username'] ?: null,
                'timeout' => 3, 'read_timeout' => 3,
            ], fn ($v) => $v !== null),
        ]);
        try {
            app('redis')->purge('installer');
            $pong = app('redis')->connection('installer')->command('ping');
        } catch (\Throwable $e) {
            return back()->withInput($request->except('password'))->withErrors(['redis' => 'Could not connect to Redis: '.$this->clean($e->getMessage())]);
        }
        if (! in_array(strtoupper((string) (is_object($pong) ? (string) $pong : $pong)), ['PONG', '1', 'TRUE'], true) && $pong !== true) {
            return back()->withInput($request->except('password'))->withErrors(['redis' => 'Redis answered unexpectedly. Check the host and port.']);
        }

        $use = $data['use'] ?? [];
        $this->env->set([
            'REDIS_CLIENT' => $client,
            'REDIS_HOST' => $data['host'],
            'REDIS_PORT' => (int) $data['port'],
            'REDIS_USERNAME' => $data['username'] ?: null,
            'REDIS_PASSWORD' => $data['password'] ?: null,
            'REDIS_DB' => (int) $data['db'],
            'CACHE_STORE' => in_array('cache', $use, true) ? 'redis' : 'database',
            'SESSION_DRIVER' => in_array('session', $use, true) ? 'redis' : 'database',
            'QUEUE_CONNECTION' => in_array('queue', $use, true) ? 'redis' : 'database',
        ]);
        Installer::complete('redis');
        $this->clearConfigCache();

        return redirect()->route('install.step', 'mail')->with('status', 'Redis connected.');
    }

    // ------------------------------------------------------------- Step 4

    public function mail(Request $request): RedirectResponse
    {
        $this->guard('mail');
        $data = $request->validate([
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'encryption' => ['required', Rule::in(['tls', 'ssl'])],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'from_address' => ['required', 'email', 'max:255'],
            'from_name' => ['required', 'string', 'max:120'],
            'test_to' => ['nullable', 'email', 'max:255'],
        ]) + ['username' => null, 'password' => null, 'test_to' => null];

        // 1. Connect and log in to the SMTP server (no email is sent).
        try {
            $transport = new EsmtpTransport($data['host'], (int) $data['port'], $data['encryption'] === 'ssl');
            if ($data['username']) {
                $transport->setUsername($data['username']);
                $transport->setPassword((string) ($data['password'] ?? ''));
            }
            $transport->getStream()->setTimeout(10);
            $transport->start();
            $transport->stop();
        } catch (\Throwable $e) {
            return back()->withInput($request->except('password'))->withErrors(['smtp' => 'Could not sign in to the SMTP server: '.$this->clean($e->getMessage())]);
        }

        // 2. Optional test email.
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp' => array_merge(config('mail.mailers.smtp'), [
                'scheme' => $data['encryption'] === 'ssl' ? 'smtps' : 'smtp', 'host' => $data['host'], 'port' => (int) $data['port'],
                'username' => $data['username'], 'password' => $data['password'], 'url' => null,
            ]),
            'mail.from' => ['address' => $data['from_address'], 'name' => $data['from_name']],
        ]);
        if ($data['test_to']) {
            try {
                Mail::purge('smtp');
                Mail::mailer('smtp')->raw("Your SMTP settings work.\n\nThis test was sent by the installer on ".$request->getHost().'.', function ($m) use ($data) {
                    $m->to($data['test_to'])->subject('Test email from the installer');
                });
            } catch (\Throwable $e) {
                return back()->withInput($request->except('password'))->withErrors(['smtp' => 'Signed in, but the test email failed: '.$this->clean($e->getMessage())]);
            }
        }

        $this->env->set([
            'MAIL_MAILER' => 'smtp',
            'MAIL_SCHEME' => $data['encryption'] === 'ssl' ? 'smtps' : 'smtp',
            'MAIL_HOST' => $data['host'],
            'MAIL_PORT' => (int) $data['port'],
            'MAIL_USERNAME' => $data['username'] ?: null,
            'MAIL_PASSWORD' => $data['password'] ?: null,
            'MAIL_FROM_ADDRESS' => $data['from_address'],
            'MAIL_FROM_NAME' => $data['from_name'],
        ]);
        Installer::complete('mail');
        $this->clearConfigCache();

        return redirect()->route('install.step', 'admin')->with('status', $data['test_to'] ? "SMTP works. A test email was sent to {$data['test_to']}." : 'SMTP sign-in works.');
    }

    // ------------------------------------------------------------- Step 5

    public function admin(Request $request, Settings $settings): RedirectResponse
    {
        $this->guard('admin');
        $this->useInstalledDatabase();

        $data = $request->validate([
            'site_name' => ['required', 'string', 'max:60'],
            'app_url' => ['required', 'url', 'max:255'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()],
            'production' => ['nullable', 'boolean'],
        ]);

        $admin = new User(['name' => $data['name'], 'email' => strtolower($data['email']), 'password' => $data['password'], 'role' => 'owner']);
        $admin->forceFill(['is_admin' => true, 'email_verified_at' => now()])->save();

        $settings->set(['branding.site_name' => $data['site_name']]);

        $production = $request->boolean('production', true);
        $this->env->set([
            'APP_NAME' => $data['site_name'],
            'APP_URL' => rtrim($data['app_url'], '/'),
            'APP_ENV' => $production ? 'production' : 'local',
            'APP_DEBUG' => ! $production,
        ]);

        try {
            Artisan::call('storage:link');
        } catch (\Throwable) {
            // Some hosts don't allow symlinks; uploaded logos then need a manual link. Shown on the login page.
        }

        Installer::markInstalled(['admin' => $admin->email, 'url' => rtrim($data['app_url'], '/')]);
        $this->clearConfigCache();

        // Sessions move from files to the configured driver on the next request, so sign in afresh.
        return redirect('/login?installed=1');
    }

    // ------------------------------------------------------------- Helpers

    private function guard(string $step): void
    {
        abort_unless(Installer::canOpen($step), 403, 'Finish the earlier installation steps first.');
    }

    /** Step 5 runs in a later request: point the default connection at what step 2 saved. */
    private function useInstalledDatabase(): void
    {
        $driver = $this->env->get('DB_CONNECTION') ?: config('database.default');
        $conn = config("database.connections.$driver");
        if ($driver === 'sqlite') {
            $conn['database'] = $this->env->get('DB_DATABASE') ?: $conn['database'];
        } else {
            foreach (['host' => 'DB_HOST', 'port' => 'DB_PORT', 'database' => 'DB_DATABASE', 'username' => 'DB_USERNAME', 'password' => 'DB_PASSWORD'] as $k => $envKey) {
                $conn[$k] = $this->env->get($envKey) ?? $conn[$k];
            }
        }
        config(["database.connections.$driver" => $conn, 'database.default' => $driver]);
        DB::purge($driver);
    }

    private function redisClient(): ?string
    {
        return extension_loaded('redis') ? 'phpredis' : (class_exists(\Predis\Client::class) ? 'predis' : null);
    }

    private function absolutePath(string $path): string
    {
        $path = trim($path);

        return preg_match('#^([A-Za-z]:[\\\\/]|/)#', $path) ? $path : base_path($path);
    }

    private function clearConfigCache(): void
    {
        if (app()->configurationIsCached()) {
            @unlink(app()->getCachedConfigPath());
        }
    }

    /** Error text without credentials (some drivers echo the DSN or user). */
    private function clean(string $message): string
    {
        return str($message)->replaceMatches('/(password|pwd)=[^;\s)]*/i', '$1=***')->limit(300)->toString();
    }
}
