<?php

namespace Tests\Feature;

use App\Licensing\LicenseService;
use App\Licensing\PluginPackager;
use App\Models\License;
use App\Models\LicenseActivation;
use App\Models\LicenseAddon;
use App\Models\Release;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/** /api/license/v1/addons/* (LabelDesk's App\Plugins\AddonLicenseService) and add-on plugin packages. */
class AddonLicenseApiTest extends TestCase
{
    use RefreshDatabase;

    private const DOMAIN = 'music.lowtide.com';   // live on the demo Business license

    private User $customer;

    private User $admin;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Http::preventStrayRequests();
        Http::fake(['ipapi.co/*' => Http::response(['error' => true], 429)]);
        Mail::fake();
        config(['app.url' => 'https://musicdesk.tech']);
        $this->customer = User::where('email', 'demo@lowtide.test')->firstOrFail();
        $this->admin = User::where('email', 'admin@musicdesk.test')->firstOrFail();
        $this->tmp = storage_path('framework/testing/addons-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function main(): License
    {
        return License::core()->whereHas('plan', fn ($q) => $q->where('code', 'business'))->where('billing_type', 'annual')->firstOrFail();
    }

    /** The main license's add-on key for $code (issuing a bought one if the plan doesn't include it). */
    private function addonKey(string $code, ?License $main = null): License
    {
        $main ??= $this->main();
        $addon = LicenseAddon::where('code', $code)->firstOrFail();

        return $main->addonLicenses()->where('addon_id', $addon->id)->first()
            ?? app(LicenseService::class)->createAddon($main, $addon, false, ['billing_type' => 'annual'], 'test')[0];
    }

    private function activate(License $key, string $addon, string $domain = self::DOMAIN)
    {
        return $this->postJson('/api/license/v1/addons/activate', [
            'key' => strtolower($key->key()), 'addon' => $addon, 'domain' => 'https://www.'.$domain.'/', 'version' => '1.0.0', 'php_version' => '8.3.12', 'instance_id' => 'inst-1',
        ]);
    }

    /**
     * What LabelDesk's AddonLicenseService::verify() checks, with the public key from GET /public-key.
     *
     * @return array<string, mixed>
     */
    private function labelDeskVerify(string $token, string $addon, string $domain = self::DOMAIN): array
    {
        $public = base64_decode($this->getJson('/api/license/v1/public-key')->json('public_key'), true);
        $last = strrpos($token, '.');
        $this->assertSame(2, substr_count($token, '.'));
        [$payload, $signature] = [substr($token, 0, $last), substr($token, $last + 1)];
        $parts = explode('.', $payload);
        $header = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true);
        $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);
        $sig = base64_decode(strtr($signature, '-_', '+/'), true);

        $this->assertSame('EdDSA', $header['alg']);
        $this->assertSame(SODIUM_CRYPTO_SIGN_BYTES, strlen($sig));
        $this->assertTrue(sodium_crypto_sign_verify_detached($sig, $payload, $public), 'signature');
        $this->assertSame('addon', $claims['typ']);
        $this->assertSame($addon, $claims['addon']);
        $this->assertSame('https://musicdesk.tech', $claims['iss']);
        $this->assertSame($domain, strtolower($claims['dom']));
        $this->assertContains($claims['status'], ['active', 'expiring']);
        $this->assertArrayHasKey('expires', $claims);
        $this->assertTrue($claims['expires'] === null || $claims['expires'] > time());
        $this->assertGreaterThan(time(), $claims['exp']);
        $this->assertIsString($claims['act']);

        return $claims;
    }

    // ------------------------------------------------------------ API

    public function test_activate_check_in_and_deactivate_an_addon(): void
    {
        $key = $this->addonKey('bulk_upload');

        $res = $this->activate($key, 'bulk_upload')->assertOk()->assertJsonStructure(['token', 'status', 'expires_at']);
        $claims = $this->labelDeskVerify($res->json('token'), 'bulk_upload');
        $this->assertSame($res->json('status'), $claims['status']);
        $activation = $key->activations()->where('domain', self::DOMAIN)->firstOrFail();
        $this->assertSame(LicenseActivation::ACTIVE, $activation->status);
        $this->assertSame($activation->uuid, $claims['act']);
        $this->assertSame('inst-1', $activation->instance_id);

        // Re-activating the same domain is allowed (new token, same activation).
        $this->activate($key, 'bulk_upload')->assertOk();
        $this->assertSame(1, $key->activations()->count());

        $check = $this->postJson('/api/license/v1/addons/check-in', ['token' => $res->json('token'), 'version' => '1.0.1'])->assertOk();
        $this->assertSame($activation->uuid, $this->labelDeskVerify($check->json('token'), 'bulk_upload')['act']);
        $this->assertSame('1.0.1', $activation->fresh()->version);

        // Add-on and main tokens are not interchangeable.
        $this->postJson('/api/license/v1/check-in', ['token' => $res->json('token')])->assertStatus(401)->assertJsonPath('error.code', 'invalid_token');
        $mainToken = $this->postJson('/api/license/v1/activate', ['key' => $this->main()->key(), 'domain' => self::DOMAIN])->assertOk()->json('token');
        $this->postJson('/api/license/v1/addons/check-in', ['token' => $mainToken])->assertStatus(401)->assertJsonPath('error.code', 'invalid_token');

        $this->postJson('/api/license/v1/addons/deactivate', ['token' => $check->json('token')])->assertNoContent();
        $this->assertSame(LicenseActivation::DEACTIVATED, $activation->fresh()->status);
        $this->postJson('/api/license/v1/addons/check-in', ['token' => $check->json('token')])->assertForbidden()->assertJsonPath('error.code', 'activation_deactivated');

        // Activating again with the key works.
        $this->activate($key, 'bulk_upload')->assertOk();
    }

    public function test_refusals_use_the_license_api_error_format(): void
    {
        $bulk = $this->addonKey('bulk_upload');

        $this->postJson('/api/license/v1/addons/activate', ['key' => 'LDAD-AAAA-BBBB-CCCC-DDDD', 'addon' => 'bulk_upload', 'domain' => self::DOMAIN])
            ->assertNotFound()->assertJsonPath('error.code', 'invalid_key')->assertJsonStructure(['error' => ['code', 'message', 'field']]);
        $this->activate($bulk, 'rest_api')->assertStatus(409)->assertJsonPath('error.code', 'addon_mismatch');
        $this->activate($this->main(), 'bulk_upload')->assertStatus(409)->assertJsonPath('error.code', 'addon_mismatch');
        $this->activate($bulk, 'bulk_upload', 'elsewhere.example.com')->assertStatus(409)->assertJsonPath('error.code', 'addon_domain_not_licensed');
        $this->postJson('/api/license/v1/addons/activate', ['key' => $bulk->key(), 'domain' => self::DOMAIN])
            ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')->assertJsonPath('error.field', 'addon');
        $this->postJson('/api/license/v1/addons/check-in', ['token' => 'a.b.c'])->assertStatus(401)->assertJsonPath('error.code', 'invalid_token');

        $bulk->update(['status' => License::SUSPENDED]);
        $this->activate($bulk, 'bulk_upload')->assertForbidden()->assertJsonPath('error.code', 'license_suspended');
        $bulk->update(['status' => License::REVOKED]);
        $this->activate($bulk, 'bulk_upload')->assertForbidden()->assertJsonPath('error.code', 'license_revoked');
    }

    public function test_expired_addon_key_and_unusable_main_license_stop_the_addon(): void
    {
        $key = $this->addonKey('rest_api');
        $token = $this->activate($key, 'rest_api')->assertOk()->json('token');

        // An expired add-on key stops (LabelDesk itself would only turn read-only).
        $key->update(['expires_at' => now()->subDay(), 'included' => false, 'billing_type' => 'annual']);
        $this->postJson('/api/license/v1/addons/check-in', ['token' => $token])->assertForbidden()->assertJsonPath('error.code', 'license_expired');

        $key->update(['expires_at' => now()->addDays(10)]);
        $res = $this->postJson('/api/license/v1/addons/check-in', ['token' => $token])->assertOk()->assertJsonPath('status', 'expiring');
        $this->assertSame($key->fresh()->expires_at->timestamp, $this->labelDeskVerify($res->json('token'), 'rest_api')['expires']);

        $this->main()->update(['status' => License::SUSPENDED]);
        $this->postJson('/api/license/v1/addons/check-in', ['token' => $token])->assertForbidden()->assertJsonPath('error.code', 'license_suspended');
    }

    public function test_addon_endpoints_are_rate_limited(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/api/license/v1/addons/deactivate', ['token' => 'x.y.z']);
        }
        $this->postJson('/api/license/v1/addons/deactivate', ['token' => 'x.y.z'])->assertStatus(429);
    }

    // ------------------------------------------------------------ packages

    /** Use a throw-away plugin signing key pair for the test. */
    private function useThrowawaySigningKey(): string
    {
        $pair = sodium_crypto_sign_keypair();
        $path = $this->tmp.'/plugin-signing.key';
        file_put_contents($path, base64_encode(sodium_crypto_sign_secretkey($pair)));
        config(['plugins.public_key' => base64_encode(sodium_crypto_sign_publickey($pair)), 'plugins.signing_key_path' => $path]);

        return $path;
    }

    /** A plugin folder zipped as people do: optionally with the top folder, optionally signed. */
    private function pluginZip(string $addon = 'bulk_upload', string $version = '1.0.0', bool $withFolder = true, ?string $signWith = null): string
    {
        $files = [
            'plugin.json' => json_encode(['slug' => 'bulk-upload', 'name' => 'Bulk upload', 'version' => $version, 'addon' => $addon,
                'namespace' => 'LabelDesk\\Addons\\BulkUpload\\', 'provider' => 'LabelDesk\\Addons\\BulkUpload\\BulkUploadServiceProvider']),
            'src/BulkUploadServiceProvider.php' => "<?php\n// provider\n",
            'routes/web.php' => "<?php\n",
            'tests/ExampleTest.php' => "<?php\n",   // not packaged
        ];
        $prefix = $withFolder ? 'bulk-upload/' : '';
        if ($signWith) {
            unset($files['tests/ExampleTest.php']);
            $hashes = array_map(fn ($c) => hash('sha256', $c), $files);
            ksort($hashes);
            $signed = json_encode(['slug' => 'bulk-upload', 'version' => $version, 'addon' => $addon, 'files' => $hashes], JSON_UNESCAPED_SLASHES);
            $files['signature.json'] = json_encode(['alg' => 'Ed25519', 'kid' => 'x', 'signed' => base64_encode($signed), 'signature' => base64_encode(sodium_crypto_sign_detached($signed, $signWith))]);
        }
        $path = $this->tmp.'/'.bin2hex(random_bytes(4)).'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        foreach ($files as $name => $content) {
            $zip->addFromString($prefix.$name, $content);
        }
        $zip->close();

        return $path;
    }

    private function upload(string $path, string $version = '1.0.0', string $addon = 'bulk_upload')
    {
        return $this->actingAs($this->admin)->post(route('admin.releases.store'), [
            'addon_id' => LicenseAddon::where('code', $addon)->value('id'), 'version' => $version, 'channel' => 'stable',
            'released_at' => now()->toDateString(), 'published' => '1',
            'package' => new UploadedFile($path, 'bulk-upload.zip', 'application/zip', null, true),
        ]);
    }

    public function test_unsigned_plugin_upload_is_signed_and_downloadable_by_key_holders(): void
    {
        Storage::fake('local');
        $this->useThrowawaySigningKey();

        $this->upload($this->pluginZip(withFolder: false))->assertSessionHasNoErrors();
        $release = Release::where('version', '1.0.0')->whereHas('addon', fn ($q) => $q->where('code', 'bulk_upload'))->firstOrFail();
        $this->assertTrue($release->hasFile());

        // The stored package passes LabelDesk's checks and leaves tests out.
        $stored = Storage::disk('local')->path($release->file_path);
        $this->assertSame('bulk_upload', app(PluginPackager::class)->verifyFile($stored)['addon']);
        $zip = new ZipArchive;
        $zip->open($stored);
        $this->assertNotFalse($zip->getFromName('bulk-upload/signature.json'));
        $this->assertFalse($zip->getFromName('bulk-upload/tests/ExampleTest.php'));
        $zip->close();
        $this->assertSame(hash_file('sha256', $stored), $release->sha256);

        // A customer with the key downloads it; a key for another add-on can't.
        $key = $this->addonKey('bulk_upload');
        $this->actingAs($this->customer)->get(route('dashboard.downloads'))->assertOk()->assertSee('Bulk upload')->assertSee('Latest');
        $this->actingAs($this->customer)->get(route('dashboard.downloads.file', [$release, 'license' => $key->uuid]))->assertOk()->assertDownload($release->fileName());
        $this->actingAs($this->customer)->get(route('dashboard.downloads.file', [$release, 'license' => $this->addonKey('rest_api')->uuid]))->assertRedirect();
        $this->actingAs($this->customer)->get(route('dashboard.downloads.file', [$release, 'license' => $this->main()->uuid]))->assertRedirect();
        $this->actingAs($this->customer)->get('/account/addons')->assertRedirect(route('dashboard.downloads').'#addons');
    }

    public function test_signed_plugin_uploads_are_verified(): void
    {
        Storage::fake('local');
        $path = $this->useThrowawaySigningKey();
        $secret = base64_decode(file_get_contents($path));
        unlink($path);   // signed packages don't need the key on the server

        $this->upload($this->pluginZip(signWith: $secret))->assertSessionHasNoErrors();
        $this->upload($this->pluginZip(version: '1.0.1', signWith: sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())), '1.0.1')->assertSessionHasErrors('package');
        $this->upload($this->pluginZip(version: '1.0.2'), '1.0.2')->assertSessionHasErrors(['package' => 'This package is not signed and the plugin signing key is not installed on this server. Upload a ZIP built with LabelDesk\'s scripts/build-plugin.php.']);

        // plugin.json must match the release's add-on and version.
        $this->useThrowawaySigningKey();
        $this->upload($this->pluginZip(addon: 'rest_api'), '1.1.0')->assertSessionHasErrors('package');
        $this->upload($this->pluginZip(version: '1.2.0'), '1.3.0')->assertSessionHasErrors('package');
        $this->assertSame(1, Release::whereHas('addon', fn ($q) => $q->where('code', 'bulk_upload'))->count());
    }
}
