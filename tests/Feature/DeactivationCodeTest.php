<?php

namespace Tests\Feature;

use App\Mail\DeactivationCodeMail;
use App\Models\License;
use App\Models\LicenseActivation;
use App\Models\Release;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** LabelDesk 4.3.2 license-server changes (docs/musicdesk-portal-changes-4.3.2.md in the LabelDesk repo). */
class DeactivationCodeTest extends TestCase
{
    use RefreshDatabase;

    private License $license;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Http::preventStrayRequests();
        Http::fake(['ipapi.co/*' => Http::response(['error' => true], 429)]);
        Mail::fake();
        $this->license = License::core()->whereHas('plan', fn ($q) => $q->where('code', 'business'))->where('billing_type', 'annual')->firstOrFail();
    }

    private function activate(string $domain, string $instance = 'inst-1', ?License $license = null): string
    {
        return $this->postJson('/api/license/v1/activate', ['key' => ($license ?? $this->license)->key(), 'domain' => $domain, 'instance_id' => $instance, 'environment' => 'staging'])
            ->assertOk()->json('token');
    }

    /** Request a code and return it from the email. */
    private function code(string $token): string
    {
        $this->postJson('/api/license/v1/deactivate/code', ['token' => $token])->assertOk();
        $code = null;
        Mail::assertSent(DeactivationCodeMail::class, function ($m) use (&$code) {
            $code = $m->code;

            return true;
        });

        return $code;
    }

    public function test_code_is_emailed_to_the_owner_masked_and_recorded(): void
    {
        $token = $this->activate('dev.lowtide.com');
        $owner = User::where('email', 'demo@lowtide.test')->firstOrFail();

        $res = $this->postJson('/api/license/v1/deactivate/code', ['token' => $token])->assertOk()
            ->assertJsonPath('sent', true)->assertJsonPath('expires_in', 600);
        $this->assertSame('d•••@l•••.test', $res->json('sent_to'));
        $this->assertStringNotContainsString($owner->email, $res->getContent());

        Mail::assertSent(DeactivationCodeMail::class, fn ($m) => $m->hasTo($owner->email) && preg_match('/^\d{6}$/', $m->code) && $m->domain === 'dev.lowtide.com');
        $mail = Mail::sent(DeactivationCodeMail::class)->first();
        $this->assertSame('Your LabelDesk deactivation code', $mail->envelope()->subject);
        $this->assertStringContainsString($mail->code, $mail->render());

        $activation = $this->license->activations()->where('domain', 'dev.lowtide.com')->firstOrFail();
        $this->assertNotSame($mail->code, $activation->deactivation_code_hash, 'stored hashed');
        $event = $this->license->events()->where('event', 'deactivation_code_sent')->firstOrFail();
        $this->assertSame('dev.lowtide.com', $event->details['domain']);
        $this->assertNotEmpty($event->details['ip']);
    }

    public function test_manual_deactivation_needs_a_valid_single_use_code(): void
    {
        $token = $this->activate('dev.lowtide.com');

        $this->postJson('/api/license/v1/deactivate', ['token' => $token])->assertStatus(422)->assertJsonPath('error.code', 'code_required');
        $this->postJson('/api/license/v1/deactivate', ['token' => $token, 'reason' => 'manual', 'code' => '000000'])->assertStatus(422)->assertJsonPath('error.code', 'invalid_code');

        $code = $this->code($token);
        $this->postJson('/api/license/v1/deactivate', ['token' => $token, 'code' => $code])->assertNoContent();
        $this->assertSame(LicenseActivation::DEACTIVATED, $this->license->activations()->where('domain', 'dev.lowtide.com')->value('status'));
        $this->assertSame('manual', $this->license->events()->where('event', 'domain_deactivated')->first()->details['reason']);

        // Single use: the token's activation is gone now anyway.
        $this->postJson('/api/license/v1/deactivate', ['token' => $token, 'code' => $code])->assertForbidden()->assertJsonPath('error.code', 'activation_deactivated');
    }

    public function test_code_expires_and_new_request_replaces_it(): void
    {
        $token = $this->activate('dev.lowtide.com');
        $first = $this->code($token);
        Mail::fake();
        $second = $this->code($token);
        if ($first !== $second) {
            $this->postJson('/api/license/v1/deactivate', ['token' => $token, 'code' => $first])->assertJsonPath('error.code', 'invalid_code');
        }

        $this->travel(11)->minutes();
        $this->postJson('/api/license/v1/deactivate', ['token' => $token, 'code' => $second])->assertStatus(422)->assertJsonPath('error.code', 'invalid_code');
    }

    public function test_five_wrong_attempts_void_the_code(): void
    {
        $token = $this->activate('dev.lowtide.com');
        $code = $this->code($token);
        $wrong = $code === '111111' ? '222222' : '111111';
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/license/v1/deactivate', ['token' => $token, 'code' => $wrong])->assertJsonPath('error.code', 'invalid_code');
        }
        $this->postJson('/api/license/v1/deactivate', ['token' => $token, 'code' => $code])->assertStatus(422)->assertJsonPath('error.code', 'invalid_code');
    }

    public function test_at_most_three_code_requests_per_15_minutes(): void
    {
        $token = $this->activate('dev.lowtide.com');
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/license/v1/deactivate/code', ['token' => $token])->assertOk();
        }
        $this->postJson('/api/license/v1/deactivate/code', ['token' => $token])->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
        $this->travel(16)->minutes();
        $this->postJson('/api/license/v1/deactivate/code', ['token' => $token])->assertOk();
    }

    public function test_code_request_errors_match_check_in(): void
    {
        $this->postJson('/api/license/v1/deactivate/code', ['token' => 'nope'])->assertStatus(401)->assertJsonPath('error.code', 'invalid_token');
    }

    public function test_transfer_without_code_only_right_after_the_same_install_activated_another_domain(): void
    {
        $old = $this->activate('dev.lowtide.com', 'inst-9');

        // No new activation yet: treated as manual.
        $this->postJson('/api/license/v1/deactivate', ['token' => $old, 'reason' => 'transfer'])->assertStatus(422)->assertJsonPath('error.code', 'code_required');

        $this->activate('dev2.lowtide.com', 'inst-9');
        $this->postJson('/api/license/v1/deactivate', ['token' => $old, 'reason' => 'transfer'])->assertNoContent();
        $this->assertSame('transfer', $this->license->events()->where('event', 'domain_deactivated')->first()->details['reason']);
    }

    public function test_transfer_from_another_installation_or_too_late_needs_the_code(): void
    {
        $old = $this->activate('dev.lowtide.com', 'inst-a');
        $this->activate('dev2.lowtide.com', 'inst-b');
        $this->postJson('/api/license/v1/deactivate', ['token' => $old, 'reason' => 'transfer'])->assertJsonPath('error.code', 'code_required');

        $this->activate('dev3.lowtide.com', 'inst-a');
        $this->travel(11)->minutes();
        $this->postJson('/api/license/v1/deactivate', ['token' => $old, 'reason' => 'transfer'])->assertJsonPath('error.code', 'code_required');
    }

    public function test_key_change_on_the_same_domain_frees_the_old_slot(): void
    {
        $other = License::core()->whereHas('plan', fn ($q) => $q->where('code', 'starter'))->where('billing_type', 'lifetime')->firstOrFail();
        $oldToken = $this->postJson('/api/license/v1/activate', ['key' => $other->key(), 'domain' => 'labels.sunsetwax.co', 'instance_id' => 'inst-k'])->assertOk()->json('token');

        // A different installation can't take the domain…
        $this->postJson('/api/license/v1/activate', ['key' => $this->license->key(), 'domain' => 'labels.sunsetwax.co', 'instance_id' => 'someone-else', 'environment' => 'staging'])
            ->assertStatus(409)->assertJsonPath('error.code', 'domain_in_use');

        // …but the same installation activating its new key there is not domain_in_use.
        $this->postJson('/api/license/v1/activate', ['key' => $this->license->key(), 'domain' => 'labels.sunsetwax.co', 'instance_id' => 'inst-k', 'environment' => 'staging'])->assertOk();

        // Then it releases the old key without a code.
        $this->postJson('/api/license/v1/deactivate', ['token' => $oldToken, 'reason' => 'key_change'])->assertNoContent();
        $this->assertSame(0, $other->activations()->where('domain', 'labels.sunsetwax.co')->where('status', '!=', 'deactivated')->count());
        $this->assertSame('key_change', $other->events()->where('event', 'domain_deactivated')->first()->details['reason']);
        $this->assertSame(0, $other->fresh()->domainsUsed(), 'slot free again');
    }

    public function test_key_change_without_a_new_key_activation_needs_the_code(): void
    {
        $token = $this->activate('dev.lowtide.com', 'inst-z');
        $this->postJson('/api/license/v1/deactivate', ['token' => $token, 'reason' => 'key_change'])->assertJsonPath('error.code', 'code_required');
    }

    public function test_portal_and_admin_deactivation_need_no_code_and_show_activity(): void
    {
        $token = $this->activate('dev.lowtide.com');
        $this->postJson('/api/license/v1/deactivate/code', ['token' => $token])->assertOk();

        $customer = User::where('email', 'demo@lowtide.test')->firstOrFail();
        $this->actingAs($customer)->get(route('dashboard.licenses'))->assertOk()
            ->assertSee('Deactivation code sent for dev.lowtide.com')->assertSee('IP 127.0.0.1')
            ->assertSee('needs a code we email to this account’s owner', false);

        $activation = $this->license->activations()->where('domain', 'dev.lowtide.com')->firstOrFail();
        $this->actingAs($customer)->deleteJson(route('dashboard.licenses.domains.deactivate', [$this->license, $activation]))->assertOk();
        $this->assertSame(LicenseActivation::DEACTIVATED, $activation->fresh()->status);

        $admin = User::where('email', 'admin@musicdesk.test')->firstOrFail();
        $this->actingAs($admin)->get(route('admin.licenses.show', $this->license))->assertOk()->assertSee('Deactivation code sent for dev.lowtide.com')->assertSee('IP 127.0.0.1');
    }

    public function test_versions_are_compared_as_versions(): void
    {
        Release::query()->create(['version' => '4.3.9', 'channel' => 'stable', 'released_at' => '2026-12-01', 'published' => true]);
        Release::query()->create(['version' => '4.3.10', 'channel' => 'stable', 'released_at' => '2026-12-01', 'published' => true]);
        $this->assertSame('4.3.10', Release::latestVersion() === '—' ? null : Release::latest()->version);

        $capped = $this->license;
        $capped->update(['max_version' => '4.3.9']);
        $this->assertFalse($capped->fresh()->mayDownload(Release::where('version', '4.3.10')->first()), '4.3.10 is newer than 4.3.9');
        $this->assertTrue($capped->fresh()->mayDownload(Release::where('version', '4.3.2')->first()));

        // Lapsed support: the cap is the highest version released before support ended.
        $capped->update(['max_version' => null, 'support_until' => '2026-12-02']);
        $this->travel(30)->days();
        $this->assertSame('4.3.10', $capped->fresh()->maxVersion());
    }

    public function test_release_4_3_2_is_published_with_upgrade_notes(): void
    {
        $r = Release::core()->where('version', '4.3.2')->firstOrFail();
        $this->assertTrue($r->published);
        $this->assertStringContainsString('MariaDB 10.6', $r->requirements['database']);
        $this->assertStringContainsString('Upgrade database', $r->requirements['extras']);
        $this->assertSame('4.3.3', Release::latestVersion());
    }
}
