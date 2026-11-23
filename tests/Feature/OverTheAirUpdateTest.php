<?php

namespace Tests\Feature;

use App\Licensing\CorePackager;
use App\Models\License;
use App\Models\LicenseAddon;
use App\Models\Release;
use App\Models\ReleaseDownload;
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

/** LabelDesk 4.3.3 over-the-air updates (docs/musicdesk-portal-changes-4.3.3.md in the LabelDesk repo). */
class OverTheAirUpdateTest extends TestCase
{
    use RefreshDatabase;

    private License $license;

    private User $admin;

    private string $tmp;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Http::preventStrayRequests();
        Http::fake(['ipapi.co/*' => Http::response(['error' => true], 429)]);
        Mail::fake();
        Storage::fake('local');

        $this->tmp = sys_get_temp_dir().'/ota-test-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->tmp);
        $pair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($pair);
        file_put_contents($this->tmp.'/plugin-signing.key', base64_encode($this->secret));
        config(['plugins.public_key' => base64_encode(sodium_crypto_sign_publickey($pair)), 'plugins.signing_key_path' => $this->tmp.'/plugin-signing.key']);

        $this->license = License::core()->whereHas('plan', fn ($q) => $q->where('code', 'business'))->where('billing_type', 'annual')->firstOrFail();
        $this->admin = User::where('email', 'admin@musicdesk.test')->firstOrFail();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    // ------------------------------------------------------------ helpers

    /** A LabelDesk release ZIP: files at the root, or in $folder, optionally signed. */
    private function coreZip(string $version, ?string $folder = null, ?string $signWith = null, array $extra = []): string
    {
        $files = ['artisan' => "#!/usr/bin/env php\n", '.env.example' => "APP_NAME=LabelDesk\n", 'composer.json' => '{}'] + $extra;
        foreach (['app/Models/Release.php', 'bootstrap/app.php', 'config/app.php', 'database/migrations/x.php', 'public/index.php', 'resources/views/a.blade.php', 'routes/web.php', 'vendor/autoload.php'] as $f) {
            $files[$f] = "<?php // {$f} {$version}\n";
        }
        if ($signWith) {
            $hashes = array_map(fn ($c) => hash('sha256', $c), $files);
            ksort($hashes);
            $signed = json_encode(['product' => 'labeldesk', 'version' => $version, 'files' => $hashes], JSON_UNESCAPED_SLASHES);
            $files['signature.json'] = json_encode(['alg' => 'Ed25519', 'kid' => 'x', 'signed' => base64_encode($signed), 'signature' => base64_encode(sodium_crypto_sign_detached($signed, $signWith))]);
        }
        $path = $this->tmp.'/'.bin2hex(random_bytes(4)).'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        foreach ($files as $name => $content) {
            $zip->addFromString(($folder ? $folder.'/' : '').$name, $content);
        }
        $zip->close();

        return $path;
    }

    private function uploadCore(string $path, string $version, array $fields = [])
    {
        return $this->actingAs($this->admin)->post(route('admin.releases.store'), [
            'version' => $version, 'channel' => 'stable', 'released_at' => now()->toDateString(), 'published' => '1',
            'package' => new UploadedFile($path, 'labeldesk.zip', 'application/zip', null, true),
        ] + $fields);
    }

    /** A published release with a (fake) signed package stored. */
    private function release(string $version, ?LicenseAddon $addon = null, ?string $date = null, array $attrs = []): Release
    {
        $path = 'releases/'.($addon?->code ?? 'labeldesk').'-'.$version.'.zip';
        Storage::disk('local')->put($path, 'zip bytes of '.$version);

        return Release::create([
            'addon_id' => $addon?->id, 'version' => $version, 'channel' => 'stable', 'released_at' => $date ?? now()->toDateString(), 'published' => true,
            'notes' => [['kind' => 'new', 'text' => 'Something new'], ['kind' => 'fixed', 'text' => 'A <b>bug</b>']],
            'file_path' => $path, 'size_bytes' => strlen('zip bytes of '.$version), 'sha256' => hash('sha256', 'zip bytes of '.$version), 'signed' => true,
        ] + $attrs);
    }

    private function activate(?License $license = null, string $domain = 'dev.lowtide.com'): string
    {
        return $this->postJson('/api/license/v1/activate', ['key' => ($license ?? $this->license)->key(), 'domain' => $domain, 'instance_id' => 'inst-1', 'environment' => 'staging'])
            ->assertOk()->json('token');
    }

    private function activateAddon(License $addonLicense, string $domain = 'dev.lowtide.com'): string
    {
        return $this->postJson('/api/license/v1/addons/activate', ['key' => $addonLicense->key(), 'addon' => $addonLicense->addon->code, 'domain' => $domain])
            ->assertOk()->json('token');
    }

    private function ddex(): LicenseAddon
    {
        return LicenseAddon::where('code', 'ddex')->firstOrFail();
    }

    // ------------------------------------------------------------ release packages

    public function test_unsigned_labeldesk_zip_is_signed_on_upload(): void
    {
        $this->uploadCore($this->coreZip('4.3.4'), '4.3.4', ['requires_php' => '8.2'])->assertSessionHasNoErrors()->assertSessionHas('toast', 'Version 4.3.4 saved (package signed)');

        $release = Release::core()->where('version', '4.3.4')->firstOrFail();
        $this->assertTrue($release->signed);
        $this->assertSame('8.2', $release->requires_php);
        $stored = Storage::disk('local')->path($release->file_path);
        $this->assertSame(hash_file('sha256', $stored), $release->sha256);
        app(CorePackager::class)->verifyFile($stored, '4.3.4');

        // Layout LabelDesk expects: labeldesk-4.3.4/… with signature.json listing every other file.
        $zip = new ZipArchive;
        $zip->open($stored);
        $sig = json_decode($zip->getFromName('labeldesk-4.3.4/signature.json'), true);
        $claims = json_decode(base64_decode($sig['signed']), true);
        $this->assertSame(['Ed25519', substr(hash('sha256', base64_decode(config('plugins.public_key'))), 0, 16)], [$sig['alg'], $sig['kid']]);
        $this->assertSame(['labeldesk', '4.3.4'], [$claims['product'], $claims['version']]);
        $this->assertSame(hash('sha256', "#!/usr/bin/env php\n"), $claims['files']['artisan']);
        $this->assertArrayHasKey('.env.example', $claims['files']);
        $this->assertSame(11, count($claims['files']));
        $zip->close();
    }

    public function test_signed_build_is_stored_as_is_and_bad_packages_are_refused(): void
    {
        $signed = $this->coreZip('4.3.4', 'labeldesk-4.3.4', $this->secret);
        $this->uploadCore($signed, '4.3.4')->assertSessionHasNoErrors()->assertSessionHas('toast', 'Version 4.3.4 saved');
        $this->assertSame(hash_file('sha256', $signed), Release::core()->where('version', '4.3.4')->value('sha256'));

        $other = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $this->uploadCore($this->coreZip('4.3.5', 'labeldesk-4.3.5', $other), '4.3.5')->assertSessionHasErrors('package');
        $this->uploadCore($this->coreZip('4.3.5', 'labeldesk-4.3.5', $this->secret), '4.3.6')->assertSessionHasErrors('package');   // folder ≠ version
        $this->uploadCore($this->coreZip('4.3.5', 'LabelDesk-main'), '4.3.5')->assertSessionHasErrors(['package' => 'The folder in the ZIP is “LabelDesk-main”; it must be “labeldesk-4.3.5” for version 4.3.5. Rename it, or zip the files without a top folder.']);
        $this->uploadCore($this->coreZip('4.3.5', null, null, ['.env' => 'APP_KEY=secret']), '4.3.5')->assertSessionHasErrors('package');
        $this->uploadCore($this->coreZip('4.3.5', null, null, ['storage/logs/laravel.log' => 'log']), '4.3.5')->assertSessionHasErrors('package');
        $this->uploadCore($this->coreZip('4.3.5', null, null, ['storage/app/private/backups/db.sql.gz' => 'x']), '4.3.5')->assertSessionHasErrors('package');

        unlink(config('plugins.signing_key_path'));
        $this->uploadCore($this->coreZip('4.3.5'), '4.3.5')->assertSessionHasErrors('package');
        $this->assertFalse(Release::core()->where('version', '4.3.5')->exists());
    }

    // ------------------------------------------------------------ /updates/check

    public function test_check_offers_the_newest_release_by_version_and_records_the_check(): void
    {
        $this->release('4.3.9', date: '2026-12-10');
        $this->release('4.3.10', date: '2026-12-10', attrs: ['requires_php' => '8.2']);
        Release::create(['version' => '4.4.0', 'channel' => 'stable', 'released_at' => '2026-12-11', 'published' => true]);   // no package: not offered
        $token = $this->activate();

        $res = $this->postJson('/api/license/v1/updates/check', ['token' => $token, 'version' => '4.3.3', 'php_version' => '8.3.12', 'instance_id' => 'inst-1'])->assertOk();
        $res->assertJsonPath('labeldesk.version', '4.3.10')->assertJsonPath('labeldesk.requires_php', '8.2')->assertJsonPath('labeldesk.downloadable', true)
            ->assertJsonPath('labeldesk.reason', null)->assertJsonPath('labeldesk.released_at', '2026-12-10')
            ->assertJsonPath('labeldesk.sha256', hash('sha256', 'zip bytes of 4.3.10'))->assertJsonPath('labeldesk.size', strlen('zip bytes of 4.3.10'));
        $this->assertSame("### Added\n- Something new\n\n### Fixed\n- A bug", $res->json('labeldesk.changelog'));
        $this->assertSame('{}', json_encode($res->json('addons') ?: new \stdClass));

        $activation = $this->license->activations()->where('domain', 'dev.lowtide.com')->first();
        $this->assertSame('4.3.3', $activation->update_check_version);
        $this->assertNotNull($activation->update_checked_at);

        $this->postJson('/api/license/v1/updates/check', ['token' => $token, 'version' => '4.3.10'])->assertOk()->assertJsonPath('labeldesk', null);
        $this->postJson('/api/license/v1/updates/check', ['token' => 'nope', 'version' => '4.3.3'])->assertStatus(401)->assertJsonPath('error.code', 'invalid_token');
    }

    public function test_release_after_the_updates_period_is_listed_but_not_downloadable(): void
    {
        $token = $this->activate();
        $this->license->update(['support_until' => '2026-12-01 00:00:00']);
        $this->release('4.3.4', date: '2026-12-10');

        $this->postJson('/api/license/v1/updates/check', ['token' => $token, 'version' => '4.3.3'])->assertOk()
            ->assertJsonPath('labeldesk.version', '4.3.4')->assertJsonPath('labeldesk.downloadable', false)
            ->assertJsonPath('labeldesk.reason', 'Your updates period for LabelDesk ended on 1 Dec 2026. Renew it to get this version.');
        $this->postJson('/api/license/v1/updates/download', ['token' => $token, 'product' => 'labeldesk', 'version' => '4.3.4'])
            ->assertForbidden()->assertJsonPath('error.code', 'updates_expired');

        $this->license->update(['support_until' => now()->addYear(), 'status' => 'suspended', 'status_reason' => 'Unpaid invoice']);
        $this->postJson('/api/license/v1/updates/check', ['token' => $token, 'version' => '4.3.3'])->assertOk()
            ->assertJsonPath('labeldesk.downloadable', false)->assertJsonPath('labeldesk.reason', "Your license {$this->license->number} is suspended (Unpaid invoice). Contact support to get updates.");
        $this->postJson('/api/license/v1/updates/download', ['token' => $token, 'product' => 'labeldesk', 'version' => '4.3.4'])
            ->assertForbidden()->assertJsonPath('error.code', 'license_suspended');
    }

    public function test_addon_updates_follow_the_addon_key(): void
    {
        $token = $this->activate();
        $ddexKey = $this->license->addonLicenses()->where('addon_id', $this->ddex()->id)->firstOrFail();
        $this->release('1.3.0', $this->ddex(), attrs: ['requires_labeldesk' => '4.3.3']);

        $addons = [['addon' => 'ddex', 'slug' => 'ddex', 'version' => '1.2.0'], ['addon' => 'sso', 'slug' => 'sso', 'version' => '1.0.0']];
        $this->postJson('/api/license/v1/updates/check', ['token' => $token, 'version' => '4.3.3', 'addons' => $addons])->assertOk()
            ->assertJsonPath('addons.ddex.version', '1.3.0')->assertJsonPath('addons.ddex.requires_labeldesk', '4.3.3')
            ->assertJsonPath('addons.ddex.downloadable', false)->assertJsonMissingPath('addons.sso');

        $addons[0]['token'] = $this->activateAddon($ddexKey);
        $this->postJson('/api/license/v1/updates/check', ['token' => $token, 'version' => '4.3.3', 'addons' => $addons])->assertOk()
            ->assertJsonPath('addons.ddex.downloadable', true)->assertJsonPath('addons.ddex.reason', null);
        $this->postJson('/api/license/v1/updates/check', ['token' => $token, 'version' => '4.3.3', 'addons' => [['addon' => 'ddex', 'version' => '1.3.0', 'token' => $addons[0]['token']]]])
            ->assertOk()->assertJsonMissingPath('addons.ddex');

        // Download: needs the add-on token, for this add-on, on a domain of this license.
        $this->postJson('/api/license/v1/updates/download', ['token' => $token, 'product' => 'ddex', 'version' => '1.3.0'])->assertStatus(409)->assertJsonPath('error.code', 'addon_mismatch');
        $this->post('/api/license/v1/updates/download', ['token' => $token, 'product' => 'ddex', 'version' => '1.3.0', 'addon_token' => $addons[0]['token']])->assertOk();

        $starter = License::core()->whereHas('plan', fn ($q) => $q->where('code', 'starter'))->where('billing_type', 'lifetime')->firstOrFail();
        $contentId = $starter->addonLicenses()->firstOrFail();
        $this->activate($starter, 'labels.sunsetwax.co');
        $foreign = $this->activateAddon($contentId, 'labels.sunsetwax.co');
        $this->release('2.0.0', $contentId->addon);
        $this->postJson('/api/license/v1/updates/download', ['token' => $token, 'product' => $contentId->addon->code, 'version' => '2.0.0', 'addon_token' => $foreign])
            ->assertStatus(409)->assertJsonPath('error.code', 'addon_domain_not_licensed');
        $this->postJson('/api/license/v1/updates/download', ['token' => $token, 'product' => 'ddex', 'version' => '1.3.0', 'addon_token' => $foreign])
            ->assertStatus(409)->assertJsonPath('error.code', 'addon_mismatch');
    }

    // ------------------------------------------------------------ /updates/download

    public function test_download_streams_the_zip_logs_it_and_is_limited(): void
    {
        $token = $this->activate();
        $release = $this->release('4.3.4');

        $res = $this->post('/api/license/v1/updates/download', ['token' => $token, 'product' => 'labeldesk', 'version' => '4.3.4', 'instance_id' => 'inst-1'])->assertOk();
        $this->assertSame('application/zip', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename=labeldesk-4.3.4.zip', $res->headers->get('Content-Disposition'));
        $this->assertSame((string) strlen('zip bytes of 4.3.4'), $res->headers->get('Content-Length'));
        $this->assertSame('zip bytes of 4.3.4', $res->streamedContent());

        $log = ReleaseDownload::sole();
        $this->assertSame([$release->id, $this->license->id, 'ota', 'dev.lowtide.com'], [$log->release_id, $log->license_id, $log->channel, $log->domain]);
        $event = $this->license->events()->where('event', 'release_downloaded')->firstOrFail();
        $this->assertSame('Downloaded 4.3.4 over the air on dev.lowtide.com', $event->label());

        $this->postJson('/api/license/v1/updates/download', ['token' => $token, 'product' => 'labeldesk', 'version' => '9.9.9'])->assertNotFound()->assertJsonPath('error.code', 'release_not_found');
        $release->update(['published' => false]);
        $this->postJson('/api/license/v1/updates/download', ['token' => $token, 'product' => 'labeldesk', 'version' => '4.3.4'])->assertNotFound()->assertJsonPath('error.code', 'release_not_found');
        $release->update(['published' => true]);

        for ($i = 1; $i < 10; $i++) {
            $this->post('/api/license/v1/updates/download', ['token' => $token, 'product' => 'labeldesk', 'version' => '4.3.4'])->assertOk();
        }
        $this->postJson('/api/license/v1/updates/download', ['token' => $token, 'product' => 'labeldesk', 'version' => '4.3.4'])->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
        $this->assertSame(10, ReleaseDownload::where('channel', 'ota')->count());
    }

    // ------------------------------------------------------------ portal and admin

    public function test_portal_and_admin_show_versions_checks_and_download_counts(): void
    {
        $token = $this->activate();
        $this->release('4.3.4', attrs: ['requires_php' => '8.2']);
        $this->postJson('/api/license/v1/updates/check', ['token' => $token, 'version' => '4.3.3'])->assertOk();
        $this->post('/api/license/v1/updates/download', ['token' => $token, 'product' => 'labeldesk', 'version' => '4.3.4'])->assertOk();

        $customer = User::where('email', 'demo@lowtide.test')->firstOrFail();
        $this->actingAs($customer)->get(route('dashboard.licenses'))->assertOk()
            ->assertSee('&quot;update_check&quot;:&quot;', false)->assertSee('Checked for updates')
            ->assertSee('can install updates itself under Administration → Updates', false)
            ->assertSee('Downloaded 4.3.4 over the air on dev.lowtide.com');
        $this->actingAs($customer)->get(route('dashboard.downloads'))->assertOk()
            ->assertSee('Automatic updates are off until you switch them on')->assertSee('Minimum PHP version');

        $this->actingAs($this->admin)->get(route('admin.licenses.show', $this->license))->assertOk()->assertSee('Update check')->assertSee('(v4.3.3)');
        $this->actingAs($this->admin)->get(route('admin.releases'))->assertOk()->assertSee('1 over the air')->assertSee('Signed')->assertSee('Minimum PHP');
    }
}
