<?php

namespace Tests\Feature;

use App\Mail\LicenseExpiringMail;
use App\Models\License;
use App\Models\Order;
use App\Models\Organisation;
use App\Models\Plan;
use App\Models\User;
use App\Support\Demo;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class LicensingTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Http::preventStrayRequests();
        Http::fake(['ipapi.co/*' => Http::response(['error' => true], 429)]);
        $this->customer = User::where('email', 'demo@lowtide.test')->firstOrFail();
        $this->admin = User::where('email', 'admin@musicdesk.test')->firstOrFail();
    }

    private function license(string $plan, string $type): License
    {
        return License::core()->whereHas('plan', fn ($q) => $q->where('code', $plan))->where('billing_type', $type)->firstOrFail();
    }

    public function test_seeded_demo_licenses_cover_the_main_states(): void
    {
        $this->assertSame('active', $this->license('business', 'annual')->displayStatus());
        $this->assertSame(4, $this->license('business', 'annual')->domainLimit());
        $this->assertTrue($this->license('starter', 'lifetime')->supportExpired());
        $this->assertSame('expiring', $this->license('starter', 'annual')->displayStatus());
    }

    public function test_token_carries_the_license_expiry_for_labeldesk_lockout(): void
    {
        $annual = $this->license('business', 'annual');
        $res = $this->postJson('/api/license/v1/activate', ['key' => $annual->key(), 'domain' => 'expiry.lowtide.test'])->assertOk();
        $claims = json_decode(base64_decode(strtr(explode('.', $res->json('token'))[1], '-_', '+/')), true);
        $this->assertSame($annual->expires_at->timestamp, $claims['expires']);
        $this->assertSame($annual->expires_at->utc()->format('Y-m-d\TH:i:s\Z'), $res->json('expires_at'));

        $lifetime = $this->license('starter', 'lifetime');
        $res = $this->postJson('/api/license/v1/activate', ['key' => $lifetime->key(), 'domain' => 'lifetime.lowtide.test'])->assertOk();
        $claims = json_decode(base64_decode(strtr(explode('.', $res->json('token'))[1], '-_', '+/')), true);
        $this->assertArrayHasKey('expires', $claims);
        $this->assertNull($claims['expires']);
    }

    public function test_license_server_activate_check_in_and_deactivate(): void
    {
        $license = $this->license('business', 'annual');
        $key = $license->key();

        $this->postJson('/api/license/v1/activate', ['key' => 'LDSK-AAAA-BBBB-CCCC-DDDD', 'domain' => 'x.test'])
            ->assertNotFound()->assertJsonPath('error.code', 'invalid_key');

        $res = $this->postJson('/api/license/v1/activate', ['key' => strtolower($key), 'domain' => 'https://New.Lowtide.com/', 'version' => '4.3.0'])
            ->assertOk()->assertJsonPath('status', 'active');
        $token = $res->json('token');
        $this->assertSame(1, $license->activations()->where('domain', 'new.lowtide.com')->where('status', 'active')->count(), 'domain normalised and activated');

        // Domain limit: 4 production domains, now all used.
        $this->postJson('/api/license/v1/activate', ['key' => $key, 'domain' => 'fifth.example.com'])
            ->assertStatus(409)->assertJsonPath('error.code', 'domain_limit_reached');
        // Staging is free.
        $this->postJson('/api/license/v1/activate', ['key' => $key, 'domain' => 'qa.lowtide.com', 'environment' => 'staging'])->assertOk();

        $this->postJson('/api/license/v1/check-in', ['token' => $token, 'version' => '4.3.1'])->assertOk();
        $this->assertSame('4.3.1', $license->activations()->where('domain', 'new.lowtide.com')->value('version'));

        $this->getJson('/api/license/v1/public-key')->assertOk()->assertJsonPath('alg', 'EdDSA');

        // Suspended licenses are refused at check-in, with the reason.
        $this->actingAs($this->admin)->post(route('admin.licenses.status', $license), ['status' => 'suspended', 'reason' => 'Unpaid invoice'])->assertRedirect();
        $this->postJson('/api/license/v1/check-in', ['token' => $token])->assertForbidden()->assertJsonPath('error.code', 'license_suspended');
        $this->actingAs($this->admin)->post(route('admin.licenses.status', $license), ['status' => 'active', 'reason' => 'Paid'])->assertRedirect();
        $this->postJson('/api/license/v1/check-in', ['token' => $token])->assertOk();

        $this->postJson('/api/license/v1/deactivate', ['token' => $token])->assertNoContent();
        $this->postJson('/api/license/v1/check-in', ['token' => $token])->assertForbidden()->assertJsonPath('error.code', 'activation_deactivated');
    }

    public function test_revoke_needs_the_license_number_typed(): void
    {
        $license = $this->license('starter', 'lifetime');
        $this->actingAs($this->admin)->post(route('admin.licenses.status', $license), ['status' => 'revoked', 'reason' => 'Chargeback'])->assertSessionHasErrors('confirm');
        $this->assertSame('active', $license->fresh()->status);

        $this->actingAs($this->admin)->post(route('admin.licenses.status', $license), ['status' => 'revoked', 'reason' => 'Chargeback', 'confirm' => $license->number]);
        $this->assertSame('revoked', $license->fresh()->status);
    }

    public function test_portal_reveal_domains_and_ownership(): void
    {
        $license = $this->license('starter', 'annual');   // 1 of 1 production domains used

        $this->actingAs($this->customer)->postJson(route('dashboard.licenses.reveal', $license))
            ->assertOk()->assertJsonPath('key', $license->key());
        $this->assertTrue($license->events()->where('event', 'key_revealed')->exists(), 'reveals are recorded');

        $this->actingAs($this->customer)->postJson(route('dashboard.licenses.domains.add', $license), ['domain' => 'second.driftrecords.net'])
            ->assertStatus(409)->assertJsonPath('code', 'domain_limit_reached');
        $this->actingAs($this->customer)->postJson(route('dashboard.licenses.domains.add', $license), ['domain' => 'dev.driftrecords.net', 'environment' => 'staging'])->assertOk();

        $stranger = User::factory()->create(['organisation_id' => Organisation::create(['name' => 'Other', 'email' => 'o@other.test', 'country' => 'US'])->id, 'role' => 'owner']);
        $this->actingAs($stranger)->postJson(route('dashboard.licenses.reveal', $license))->assertNotFound();

        $this->actingAs($this->customer)->get(route('dashboard.licenses.certificate', $license))->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_renewal_paid_by_bank_transfer_extends_the_license(): void
    {
        $license = $this->license('starter', 'annual');
        $before = $license->expires_at->copy();

        $this->actingAs($this->customer)->get(route('licenses.buy', [$license, 'renewal']))->assertOk()->assertSee('£379');
        $this->actingAs($this->customer)->post(route('licenses.buy.store', [$license, 'renewal']), ['gateway' => 'bank_transfer', 'terms' => '1'])->assertRedirect();

        $order = Order::latest('id')->firstOrFail();
        $this->assertSame('renewal', $order->kind);
        $this->assertSame('awaiting_payment', $order->status);
        $this->assertTrue($license->fresh()->expires_at->equalTo($before), 'nothing changes until paid');

        $this->actingAs($this->admin)->post(route('admin.orders.paid', $order), ['reference' => 'BACS-1'])->assertRedirect();
        $this->assertTrue($license->fresh()->expires_at->equalTo($before->copy()->addYear()));
        $this->assertSame('active', $license->fresh()->displayStatus());
    }

    public function test_extra_domains_purchase_raises_the_limit(): void
    {
        $license = $this->license('starter', 'annual');
        $this->actingAs($this->customer)->post(route('licenses.buy.store', [$license, 'extra_domains']), ['gateway' => 'bank_transfer', 'terms' => '1', 'quantity' => 2])->assertRedirect();
        $order = Order::latest('id')->firstOrFail();
        $this->actingAs($this->admin)->post(route('admin.orders.paid', $order))->assertRedirect();

        $this->assertSame(3, $license->fresh()->domainLimit());
    }

    public function test_upgrade_and_convert_to_lifetime(): void
    {
        $license = $this->license('starter', 'annual');

        $this->actingAs($this->customer)->post(route('licenses.buy.store', [$license, 'change_plan']), ['gateway' => 'bank_transfer', 'terms' => '1', 'plan' => 'business'])->assertRedirect();
        $this->actingAs($this->admin)->post(route('admin.orders.paid', Order::latest('id')->first()));
        $this->assertSame('business', $license->fresh()->plan->code);

        $this->actingAs($this->customer)->post(route('licenses.buy.store', [$license, 'convert_lifetime']), ['gateway' => 'bank_transfer', 'terms' => '1'])->assertRedirect();
        $this->actingAs($this->admin)->post(route('admin.orders.paid', Order::latest('id')->first()));
        $license->refresh();
        $this->assertSame('lifetime', $license->billing_type);
        $this->assertNull($license->expires_at);
    }

    public function test_refunding_a_new_license_order_revokes_it(): void
    {
        $this->actingAs($this->admin)->post(route('admin.licenses.store'), [
            'new_org_name' => 'Wavefront Ltd', 'new_org_email' => 'billing@wavefront.test', 'new_org_country' => 'DE',
            'plan_id' => Plan::where('code', 'starter')->value('id'), 'billing_type' => 'annual', 'status' => 'active',
            'primary_domain' => 'music.wavefront.test', 'record_sale' => '1', 'amount' => 449, 'currency' => 'EUR', 'payment' => 'manual', 'paid' => '1',
        ])->assertRedirect()->assertSessionHas('new_key');

        $license = License::latest('id')->firstOrFail();
        $this->assertSame('Wavefront Ltd', $license->organisation->name);
        $this->assertSame(1, $license->activations()->count());
        $order = $license->orders()->firstOrFail();
        $this->assertSame('paid', $order->invoice->status);

        $this->actingAs($this->admin)->get(route('admin.licenses.show', $license))->assertOk()->assertSee($license->number)->assertSee('music.wavefront.test');
        $this->actingAs($this->admin)->get(route('admin.customers.show', $license->organisation))->assertOk()->assertSee('Wavefront Ltd');

        $this->actingAs($this->admin)->post(route('admin.orders.refund', $order), ['reason' => 'Changed mind'])->assertRedirect();
        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertSame('revoked', $license->fresh()->status);
    }

    public function test_downloads_follow_the_updates_period(): void
    {
        $this->actingAs($this->customer)->get('/dashboard/downloads')->assertOk()->assertSee('4.3.0');
        $this->assertFalse($this->license('starter', 'lifetime')->mayDownload(\App\Models\Release::where('version', '4.3.0')->first()), 'updates lapsed in 2025');
        $this->assertTrue($this->license('business', 'annual')->mayDownload(\App\Models\Release::where('version', '4.3.0')->first()));
    }

    public function test_expiry_reminder_is_sent_once(): void
    {
        Mail::fake();
        $this->artisan('licenses:notify-expiring')->assertSuccessful();
        Mail::assertSent(LicenseExpiringMail::class, 1);
        Mail::assertSent(LicenseExpiringMail::class, fn ($m) => $m->hasTo(Demo::DEMO_ORG_EMAIL) && $m->days === 11);

        $this->artisan('licenses:notify-expiring')->assertSuccessful();
        Mail::assertSent(LicenseExpiringMail::class, 1);

        // Reminders off: nothing is sent.
        $license = $this->license('starter', 'annual');
        $license->events()->delete();
        $license->update(['auto_renew' => false]);
        $this->artisan('licenses:notify-expiring')->assertSuccessful();
        Mail::assertSent(LicenseExpiringMail::class, 1);
    }
}
