<?php

namespace Tests\Feature;

use App\Models\AdminRole;
use App\Models\Invoice;
use App\Models\License;
use App\Models\LicenseAddon;
use App\Models\LicenseAlert;
use App\Models\Order;
use App\Models\Organisation;
use App\Models\PaymentGateway;
use App\Models\Plan;
use App\Models\User;
use App\Support\Demo;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** MusicDesk.tech 2.0.2: create order, sign in as customer / to a LabelDesk site, status add-ons, gateways list. */
class AdminToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Organisation $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Http::preventStrayRequests();
        Http::fake(['ipapi.co/*' => Http::response(['error' => true], 429)]);
        Mail::fake();
        $this->admin = User::where('email', 'admin@musicdesk.test')->firstOrFail();
        $this->org = Organisation::where('email', Demo::DEMO_ORG_EMAIL)->firstOrFail();
    }

    private function claims(string $token): array
    {
        return json_decode(base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);
    }

    public function test_login_page_no_longer_mentions_test_accounts(): void
    {
        $this->get(route('login'))->assertOk()->assertDontSee('Local test accounts');
    }

    // ------------------------------------------------------------ create order

    public function test_admin_creates_a_paid_new_license_order(): void
    {
        $this->actingAs($this->admin)->get(route('admin.orders'))->assertOk()->assertSee('Create order')->assertSee('Choose a customer');

        $plan = Plan::where('code', 'business')->firstOrFail();
        $before = License::core()->where('organisation_id', $this->org->id)->count();
        $this->actingAs($this->admin)->post(route('admin.orders.store'), [
            'organisation_id' => $this->org->id, 'kind' => 'new_license', 'plan_id' => $plan->id, 'billing' => 'lifetime', 'quantity' => 1,
            'currency' => 'USD', 'price' => 'custom', 'amount' => 2500, 'gateway' => 'manual', 'paid' => '1', 'reference' => 'WIRE-77',
        ])->assertRedirect(route('admin.orders'))->assertSessionHas('toast');

        $order = Order::latest('id')->firstOrFail();
        $this->assertSame(['new_license', 'paid', 2500.0, 'WIRE-77'], [$order->kind, $order->status, (float) $order->total, $order->gateway_payment_id]);
        $this->assertSame(Invoice::where('order_id', $order->id)->value('status'), 'paid');
        $this->assertSame($before + 1, License::core()->where('organisation_id', $this->org->id)->count());
        $this->assertSame(['business', 'lifetime', 'active', 4], [$order->license->plan->code, $order->license->billing_type, $order->license->status, $order->license->domainLimit()]);
        $this->actingAs($this->admin)->get(route('admin.orders'))->assertOk()->assertSee($order->number)->assertSee('x-teleport="body"', false);
    }

    public function test_admin_creates_an_unpaid_renewal_at_list_price_that_applies_when_paid(): void
    {
        $license = License::core()->where('organisation_id', $this->org->id)->where('billing_type', 'annual')->whereHas('plan', fn ($q) => $q->where('code', 'starter'))->firstOrFail();
        $expires = $license->expires_at;

        $this->actingAs($this->admin)->post(route('admin.orders.store'), [
            'organisation_id' => $this->org->id, 'kind' => 'renewal', 'license_id' => $license->id, 'currency' => 'USD', 'price' => 'list', 'gateway' => 'bank_transfer', 'paid' => '0',
        ])->assertRedirect();
        $order = Order::latest('id')->firstOrFail();
        $this->assertSame(['renewal', 'awaiting_payment', $license->id], [$order->kind, $order->status, $order->license_id]);
        $this->assertSame((float) $license->plan->price_annual, (float) $order->subtotal);
        $this->assertTrue($license->fresh()->expires_at->equalTo($expires), 'not applied before payment');

        $this->actingAs($this->admin)->post(route('admin.orders.paid', $order))->assertRedirect();
        $this->assertTrue($license->fresh()->expires_at->greaterThan($expires));

        // Wrong combinations are explained, not created.
        $this->actingAs($this->admin)->post(route('admin.orders.store'), ['organisation_id' => $this->org->id, 'kind' => 'renewal', 'currency' => 'USD', 'price' => 'list', 'gateway' => 'manual'])
            ->assertSessionHasErrors('license_id');
        $lifetime = License::core()->where('organisation_id', $this->org->id)->where('billing_type', 'lifetime')->firstOrFail();
        $this->actingAs($this->admin)->post(route('admin.orders.store'), ['organisation_id' => $this->org->id, 'kind' => 'renewal', 'license_id' => $lifetime->id, 'currency' => 'USD', 'price' => 'list', 'gateway' => 'manual'])
            ->assertSessionHasErrors('order');
    }

    // ------------------------------------------------------------ sign in as customer

    public function test_admin_signs_in_as_a_customer_and_returns(): void
    {
        $owner = $this->org->users()->where('role', 'owner')->firstOrFail();
        $this->actingAs($this->admin)->get(route('admin.customers'))->assertOk()->assertSee('Sign in as '.$this->org->name, false);

        $this->actingAs($this->admin)->post(route('admin.customers.impersonate', $this->org))->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($owner);
        $this->get(route('dashboard.licenses'))->assertOk()->assertSee('from the admin')->assertSee('Return to admin');
        $this->get(route('admin.dashboard'))->assertForbidden();

        $this->post(route('impersonation.stop'))->assertRedirect(route('admin.customers.show', $this->org));
        $this->assertAuthenticatedAs($this->admin);
        $this->get(route('admin.dashboard'))->assertOk();
    }

    public function test_sign_in_as_customer_needs_its_permission(): void
    {
        $role = AdminRole::where('name', 'Administrator')->firstOrFail();
        $member = User::factory()->create(['is_admin' => true, 'admin_role_id' => $role->id, 'organisation_id' => null]);
        $this->actingAs($member)->get(route('admin.customers'))->assertOk()->assertDontSee('Sign in as '.$this->org->name, false);
        $this->actingAs($member)->post(route('admin.customers.impersonate', $this->org))->assertForbidden();
        $this->actingAs($member)->post(route('admin.remote-login'), ['domain' => 'music.lowtide.com'])->assertForbidden();

        // A customer can't use the return route to become an admin.
        $customer = $this->org->users()->firstOrFail();
        $this->actingAs($customer)->post(route('impersonation.stop'))->assertForbidden();
    }

    // ------------------------------------------------------------ sign in to a LabelDesk site

    public function test_remote_login_signs_a_short_single_use_token_for_the_domain(): void
    {
        $license = License::core()->whereHas('activations', fn ($q) => $q->where('domain', 'music.lowtide.com'))->firstOrFail();

        $res = $this->actingAs($this->admin)->post(route('admin.remote-login'), ['domain' => 'Music.Lowtide.com', 'license' => $license->uuid])->assertOk()
            ->assertSee('action="https://music.lowtide.com/musicdesk/sso"', false)->assertSee('name="token"', false);
        preg_match('/name="token" value="([^"]+)"/', $res->getContent(), $m);
        $claims = $this->claims($m[1]);
        app(\App\Licensing\LicenseToken::class)->verify($m[1]);   // signed with the license key

        $this->assertSame(['admin_sso', 'music.lowtide.com', 'music.lowtide.com', $license->uuid, $this->admin->email], [$claims['typ'], $claims['aud'], $claims['dom'], $claims['lic'], $claims['by_email']]);
        $this->assertNotEmpty($claims['act']);
        $this->assertLessThanOrEqual(120, $claims['exp'] - $claims['iat']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $claims['jti']);
        $this->assertSame('MusicDesk support signed in to music.lowtide.com as Super Admin', $license->events()->where('event', 'remote_login')->firstOrFail()->label());

        // Any domain, also unlicensed / failing ones (no license claims then).
        $res = $this->actingAs($this->admin)->post(route('admin.remote-login'), ['domain' => 'cracked.example.net'])->assertOk();
        preg_match('/name="token" value="([^"]+)"/', $res->getContent(), $m);
        $this->assertNull($this->claims($m[1])['lic']);
    }

    public function test_status_and_license_pages_offer_sign_in_and_show_addons(): void
    {
        $main = License::core()->whereHas('plan', fn ($q) => $q->where('code', 'business'))->where('billing_type', 'annual')->firstOrFail();
        $ddex = $main->addonLicenses()->firstOrFail();
        $this->postJson('/api/license/v1/addons/activate', ['key' => $ddex->key(), 'addon' => $ddex->addon->code, 'domain' => 'music.lowtide.com'])->assertOk();
        LicenseAlert::create(['domain' => 'cracked.example.net', 'status' => LicenseAlert::OPEN, 'last_reason' => 'invalid_key', 'source' => 'install', 'attempts' => 1, 'first_seen_at' => now(), 'last_seen_at' => now()]);

        $this->actingAs($this->admin)->get(route('admin.license-status', ['alerts' => 'all']))->assertOk()
            ->assertSee('<span class="badge badge-flare !text-[10px]">Add-on</span>', false)->assertSee($ddex->addon->name)
            ->assertSee('Sign in to cracked.example.net as Super Admin', false)->assertSee('Sign in to music.lowtide.com as Super Admin', false);
        $this->actingAs($this->admin)->get(route('admin.licenses.show', $ddex))->assertOk()->assertSee('Sign in to music.lowtide.com as Super Admin', false);
        $this->actingAs($this->admin)->get(route('admin.licenses.show', $main))->assertOk()->assertSee('Sign in to music.lowtide.com as Super Admin', false);
    }

    // ------------------------------------------------------------ payment gateways

    public function test_gateway_list_toggles_and_saves(): void
    {
        $this->actingAs($this->admin)->get(route('admin.gateways'))->assertOk()->assertSee('Live at checkout')->assertSee('role="switch"', false);

        $this->actingAs($this->admin)->postJson(route('admin.gateways.toggle', 'stripe'), ['enabled' => true])->assertOk()
            ->assertJsonPath('enabled', true)->assertJsonPath('configured', false)->assertJsonPath('message', 'Stripe is on, but its keys are missing, so it won’t show at checkout yet');
        $this->assertTrue(PaymentGateway::where('code', 'stripe')->value('enabled'));
        $this->actingAs($this->admin)->postJson(route('admin.gateways.toggle', 'stripe'), ['enabled' => false])->assertOk()->assertJsonPath('enabled', false);
        $this->assertFalse(PaymentGateway::where('code', 'stripe')->value('enabled'));
    }
}
