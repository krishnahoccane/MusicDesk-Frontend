<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\PaymentGateway;
use App\Models\Plan;
use App\Models\User;
use App\Support\Installer\EnvWriter;
use App\Support\Installer\Installer;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Web installer: prerequisites → database → Redis (optional) → SMTP → super admin.
 * Runs against a temporary .env, storage folder and SQLite file; the real ones are never touched.
 */
class InstallerTest extends TestCase
{
    private string $tmp;

    private string $envFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'md-install-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->tmp.'/storage/app');
        $this->envFile = $this->tmp.'/.env';
        copy(base_path('.env.example'), $this->envFile);

        $this->app->useStoragePath($this->tmp.'/storage');
        $this->app->instance(EnvWriter::class, new EnvWriter($this->envFile));
        config(['app.installed' => null]);   // decided by the marker file in the temp storage
        Installer::prepareRuntime();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function env(): array
    {
        return Dotenv::parse((string) file_get_contents($this->envFile));
    }

    public function test_every_page_leads_to_the_installer_until_it_is_finished(): void
    {
        $this->assertFalse(Installer::isInstalled());

        $this->get('/')->assertRedirect('/install');
        $this->get('/dashboard')->assertRedirect('/install');
        $this->get('/install')->assertRedirect('/install/requirements');
        $this->get('/install/requirements')->assertOk()->assertSee('Check the prerequisites')->assertSee('PHP extensions');

        // Later steps are locked until earlier ones are done.
        $this->get('/install/admin')->assertRedirect('/install/requirements');
        $this->post('/install/database', ['driver' => 'sqlite', 'path' => $this->tmp.'/x.sqlite'])->assertForbidden();
        $this->post('/install/admin', [])->assertForbidden();
    }

    public function test_full_installation(): void
    {
        // Step 1
        $this->post('/install/requirements')->assertRedirect('/install/database');

        // Step 2: a wrong MySQL server is reported, nothing saved; SQLite works.
        $this->post('/install/database', ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'x', 'username' => 'x', 'password' => 's3cret'])
            ->assertSessionHasErrors('connection');
        $this->assertNotSame('mysql', $this->env()['DB_CONNECTION']);

        $db = $this->tmp.'/musicdesk.sqlite';
        $this->post('/install/database', ['driver' => 'sqlite', 'path' => $db])->assertRedirect('/install/redis');
        $this->assertFileExists($db);
        $this->assertSame('sqlite', $this->env()['DB_CONNECTION']);
        $this->assertSame($db, $this->env()['DB_DATABASE']);
        // Reference data only: currencies, plans, gateways switched off, no demo accounts.
        $this->assertSame(3, Plan::count());
        $this->assertTrue(Currency::where('code', 'INR')->exists());
        $this->assertSame(0, PaymentGateway::where('enabled', true)->count());
        $this->assertSame(0, User::count());

        // Step 3: an unreachable Redis is reported; skipping keeps everything on the database.
        $this->post('/install/redis', ['action' => 'save', 'host' => '127.0.0.1', 'port' => 1, 'db' => 0, 'use' => ['cache']])->assertSessionHasErrors('redis');
        $this->post('/install/redis', ['action' => 'skip'])->assertRedirect('/install/mail');
        $this->assertSame('database', $this->env()['CACHE_STORE']);
        $this->assertSame('skipped', Installer::progress()['redis']);

        // Step 4: SMTP sign-in is verified; an unreachable server is refused and nothing is saved.
        $this->post('/install/mail', ['host' => '127.0.0.1', 'port' => 1, 'encryption' => 'tls', 'username' => 'u', 'password' => 'p',
            'from_address' => 'billing@musicdesk.test', 'from_name' => 'MusicDesk'])->assertSessionHasErrors('smtp');
        $this->assertNotSame('smtp', $this->env()['MAIL_MAILER']);
        Installer::complete('mail');   // no SMTP server in the test environment

        // Step 5
        $this->get('/install/admin')->assertOk()->assertSee('Create the super admin');
        $this->post('/install/admin', ['site_name' => 'TWSD Music', 'app_url' => 'https://musicdesk.test/', 'name' => 'Ada Admin', 'email' => 'Ada@MusicDesk.test',
            'password' => 'short', 'password_confirmation' => 'short', 'production' => '1'])->assertSessionHasErrors('password');

        $this->post('/install/admin', ['site_name' => 'TWSD Music', 'app_url' => 'https://musicdesk.test/', 'name' => 'Ada Admin', 'email' => 'Ada@MusicDesk.test',
            'password' => 'correct-horse-42-battery', 'password_confirmation' => 'correct-horse-42-battery', 'production' => '1'])
            ->assertRedirect('/login?installed=1');

        $admin = User::where('email', 'ada@musicdesk.test')->firstOrFail();
        $this->assertTrue($admin->is_admin);
        $this->assertNull($admin->organisation_id);
        $this->assertTrue(Hash::check('correct-horse-42-battery', $admin->password));
        $this->assertSame('TWSD Music', setting('branding.site_name'));

        $env = $this->env();
        $this->assertSame('https://musicdesk.test', $env['APP_URL']);
        $this->assertSame('production', $env['APP_ENV']);
        $this->assertSame('false', $env['APP_DEBUG']);
        $this->assertSame('TWSD Music', $env['APP_NAME']);

        // Installed: the installer is gone, the marker records the admin, progress is cleaned up.
        $this->assertTrue(Installer::isInstalled());
        $this->assertFileDoesNotExist(Installer::progressPath());
        $this->assertSame('ada@musicdesk.test', json_decode(file_get_contents(Installer::markerPath()), true)['admin']);
        $this->get('/install')->assertNotFound();
        $this->get('/install/requirements')->assertNotFound();
    }

    public function test_env_writer_round_trips_awkward_values(): void
    {
        $env = app(EnvWriter::class);
        $values = [
            'DB_PASSWORD' => 'p@ss w0rd "quoted" $HOME #hash \\back',
            'MAIL_FROM_NAME' => 'Twsd Music & Co',
            'MAIL_USERNAME' => 'apikey',
            'REDIS_PASSWORD' => null,
            'APP_DEBUG' => false,
            'NEW_KEY' => 'added',
        ];
        $env->set($values);

        $parsed = $this->env();
        $this->assertSame($values['DB_PASSWORD'], $parsed['DB_PASSWORD']);
        $this->assertSame('Twsd Music & Co', $parsed['MAIL_FROM_NAME']);
        $this->assertSame('apikey', $parsed['MAIL_USERNAME']);
        $this->assertSame('null', $parsed['REDIS_PASSWORD']);
        $this->assertSame('false', $parsed['APP_DEBUG']);
        $this->assertSame('added', $parsed['NEW_KEY']);
        $this->assertSame($values['DB_PASSWORD'], $env->get('DB_PASSWORD'));

        // Keys are replaced in place, not duplicated.
        $env->set(['MAIL_USERNAME' => 'other']);
        $this->assertSame(1, substr_count(file_get_contents($this->envFile), "\nMAIL_USERNAME="));

        $this->expectException(\RuntimeException::class);
        $env->set(['bad key' => 'x']);
    }

    public function test_a_missing_app_key_is_generated_before_the_first_page(): void
    {
        file_put_contents($this->envFile, str_replace("APP_KEY=\n", '', file_get_contents($this->envFile))."APP_KEY=\n");
        config(['app.key' => '']);

        Installer::prepareRuntime();

        $this->assertStringStartsWith('base64:', $this->env()['APP_KEY']);
        $this->assertSame($this->env()['APP_KEY'], config('app.key'));
        $this->assertSame('file', config('session.driver'));
    }
}
