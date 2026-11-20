<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Organisation;
use App\Models\User;
use App\Services\Billing\BillingClient;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** musicdesk.tech ⇄ billing.twsd.me storefront API (TWSD Billing docs/LICENSING.md), faked with billing's real response shapes. */
class BillingIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://billing.test/api/v1/storefront';

    private const CUS = 'cus_9b2f1c3e-0000-4000-8000-000000000001';

    private const LIC = 'lic_7a1d2e3f-0000-4000-8000-000000000002';

    private const ORD = 'ord_22222222-0000-4000-8000-000000000004';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config(['services.wcc' => ['enabled' => true, 'url' => self::BASE, 'key' => 'sk_live_test', 'storefront' => 'musicdesk', 'webhook_secret' => 'whsec_test']]);
        $this->app->forgetInstance(BillingClient::class);
        Http::preventStrayRequests();
    }

    /** ApiPresenter::license() shape from TWSD Billing. */
    private function license(array $over = []): array
    {
        return array_merge([
            'id' => self::LIC, 'number' => 'LIC-24A7', 'customer_id' => self::CUS, 'product' => 'labeldesk',
            'plan_code' => 'starter', 'plan_name' => 'Starter', 'billing_type' => 'annual', 'status' => 'pending', 'status_reason' => null,
            'key_masked' => 'LDSK-••••-••••-••••-H8TC', 'domains_included' => 1, 'domains_extra' => 1, 'domains_used' => 1,
            'purchased_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'expires_at' => now()->addYear()->utc()->format('Y-m-d\TH:i:s\Z'),
            'support_until' => now()->addYear()->utc()->format('Y-m-d\TH:i:s\Z'), 'pending_until' => now()->addDays(14)->utc()->format('Y-m-d\TH:i:s\Z'),
            'max_version' => null, 'auto_renew' => true, 'price_paid' => ['amount' => 10832046, 'currency' => 'INR'], 'licensed_to' => 'Bassline Music Pvt Ltd',
            'activations' => [['id' => 'act_11111111-0000-4000-8000-000000000003', 'license_id' => self::LIC, 'domain' => 'portal.bassline.test',
                'environment' => 'production', 'status' => 'pending', 'server_ip' => null, 'version' => null, 'php_version' => null,
                'last_check_in_at' => null, 'online' => false, 'check_in_requested' => false]],
        ], $over);
    }

    private function fakeBilling(array $extra = []): void
    {
        Http::fake($extra + [
            'ipapi.co/*' => Http::response(['error' => true], 429),
            self::BASE.'/orders' => Http::response([
                'id' => 'ord_22222222-0000-4000-8000-000000000004', 'kind' => 'new_license', 'customer_id' => self::CUS, 'license_id' => self::LIC,
                'amount' => ['amount' => 10832046, 'currency' => 'INR'], 'payment_reference' => 'ORD-x', 'invoice_number' => 'MD-2026-000006',
                'created_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'license' => $this->license(),
            ], 201),
            self::BASE.'/licenses*' => Http::response(['data' => [$this->license()], 'next_cursor' => null]),
            self::BASE.'/customers' => Http::response(['id' => self::CUS, 'company' => 'Bassline Music Pvt Ltd', 'country' => 'IN'], 201),
            self::BASE.'/orders/*/confirm-payment' => Http::response(['id' => self::ORD, 'payment_status' => 'paid', 'license' => $this->license(['status' => 'active', 'pending_until' => null])]),
            self::BASE.'/orders/*/refund' => Http::response(['id' => self::ORD, 'payment_status' => 'refunded', 'license' => $this->license(['status' => 'revoked'])]),
        ]);
    }

    private function bankTransferCheckout(): Order
    {
        $this->post('/locale/currency', ['currency' => 'INR']);
        $this->post('/checkout/starter', [
            'billing' => 'annual', 'domains' => 1, 'addons' => ['installation'],
            'name' => 'Ravi Kumar', 'email' => 'ravi@bassline.test', 'password' => 'very-secure-pass',
            'company' => 'Bassline Music Pvt Ltd', 'country' => 'IN', 'primary_domain' => 'portal.bassline.test',
            'gateway' => 'bank_transfer', 'terms' => '1',
        ])->assertRedirect();

        return Order::latest('id')->firstOrFail();
    }

    public function test_guest_checkout_creates_the_customer_then_the_license_in_billing(): void
    {
        $this->fakeBilling();
        $order = $this->bankTransferCheckout();

        Http::assertSent(fn (Request $r) => $r->url() === self::BASE.'/customers' && $r->method() === 'POST'
            && $r->hasHeader('Idempotency-Key', 'customer-'.$order->organisation_id)
            && $r->data()['company'] === 'Bassline Music Pvt Ltd' && $r->data()['email'] === 'ravi@bassline.test'
            && $r->data()['country'] === 'IN' && $r->data()['currency'] === 'INR' && $r->data()['name'] === 'Ravi Kumar');

        Http::assertSent(function (Request $r) use ($order) {
            if ($r->url() !== self::BASE.'/orders') {
                return false;
            }
            $b = $r->data();

            return $r->method() === 'POST'
                && $r->hasHeader('Authorization', 'Bearer sk_live_test')
                && $r->hasHeader('Idempotency-Key', 'order-'.$order->number)
                && $r->hasHeader('X-Customer-Id', self::CUS)              // customer created at sign-up
                && $b['kind'] === 'new_license' && $b['product'] === 'labeldesk' && $b['plan_code'] === 'starter'
                && $b['customer'] === ['id' => self::CUS]
                && $b['amount'] === ['amount' => 10832046, 'currency' => 'INR']   // ₹1,08,320.46 in paise
                && $b['payment_status'] === 'pending' && $b['payment_provider'] === 'bank_transfer'
                && $b['extra_domains'] === 1 && $b['primary_domain'] === 'portal.bassline.test'
                && preg_match('/^MD-\d{4}-\d{6}$/', $b['invoice_number']);
        });

        $order->refresh();
        $this->assertSame(self::LIC, $order->billing_license_id);
        $this->assertSame('LIC-24A7', $order->meta['license_number']);
        $this->assertNull($order->license_key, 'Live billing never sends the key; it is revealed on request');
        $this->assertSame(self::CUS, $order->organisation->billing_org_id);
    }

    public function test_later_orders_reuse_the_customer_id(): void
    {
        $this->fakeBilling();
        $this->bankTransferCheckout();
        $this->post('/checkout/business', ['billing' => 'annual', 'domains' => 0, 'company' => 'Bassline Music Pvt Ltd', 'country' => 'IN', 'gateway' => 'bank_transfer', 'terms' => '1'])->assertRedirect();

        Http::assertSent(fn (Request $r) => $r->url() === self::BASE.'/orders'
            && $r->hasHeader('X-Customer-Id', self::CUS)
            && $r->data()['customer'] === ['id' => self::CUS]
            && $r->data()['plan_code'] === 'business');
    }

    public function test_portal_shows_billing_licenses_and_reveals_the_key(): void
    {
        $this->fakeBilling([self::BASE.'/licenses/'.self::LIC.'/reveal-key' => Http::response(['key' => 'LDSK-7F3K-Q9PX-2MWA-H8TC'])]);
        $this->bankTransferCheckout();

        $this->get('/dashboard/licenses')->assertOk()
            ->assertSee('LIC-24A7')->assertSee('LDSK-••••-••••-••••-H8TC')->assertSee('portal.bassline.test')
            ->assertSee('Awaiting your bank transfer')->assertDontSee('LDSK-7F3K-Q9PX-2MWA-H8TC');

        $this->postJson('/dashboard/licenses/'.self::LIC.'/reveal')->assertOk()->assertJson(['key' => 'LDSK-7F3K-Q9PX-2MWA-H8TC'])
            ->assertHeader('Cache-Control', 'no-store, private');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/reveal-key') && $r->hasHeader('X-Customer-Id', self::CUS) && $r->hasHeader('X-Acting-User', 'ravi@bassline.test'));

        // Another customer's license is refused before reaching billing.
        $this->postJson('/dashboard/licenses/lic_not-mine/reveal')->assertNotFound();
    }

    public function test_domain_errors_from_billing_are_shown_to_the_customer(): void
    {
        $this->fakeBilling([self::BASE.'/licenses/'.self::LIC.'/activations' => Http::response(['error' => ['code' => 'domain_limit_reached', 'message' => 'All production domains are in use.', 'field' => 'domain'], 'request_id' => 'req_1'], 409)]);
        $this->bankTransferCheckout();

        $this->postJson('/dashboard/licenses/'.self::LIC.'/domains', ['domain' => 'second.bassline.test'])
            ->assertStatus(409)->assertJsonFragment(['code' => 'domain_limit_reached']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/activations') && $r->hasHeader('Idempotency-Key') && $r->data()['domain'] === 'second.bassline.test');
    }

    public function test_bank_transfer_paid_later_is_confirmed_in_billing(): void
    {
        $this->fakeBilling();
        $order = $this->bankTransferCheckout();

        $admin = User::where('email', 'admin@musicdesk.test')->first();
        $this->actingAs($admin)->post(route('admin.orders.paid', $order), ['reference' => 'NEFT1'])->assertRedirect();

        $order->refresh();
        $this->assertSame('paid', $order->status);
        $this->assertArrayNotHasKey('billing_action', $order->meta, 'billing confirmed it, nothing left to do by hand');
        Http::assertSent(fn (Request $r) => $r->url() === self::BASE.'/orders/'.self::ORD.'/confirm-payment'
            && $r->data()['payment_reference'] === 'NEFT1' && isset($r->data()['paid_at'])
            && $r->hasHeader('X-Customer-Id', self::CUS) && $r->hasHeader('X-Acting-User', 'admin@musicdesk.test'));
        // Exactly one POST /orders (the pending one): marking it paid must not create a second license.
        $this->assertSame(1, collect(Http::recorded())->filter(fn ($p) => $p[0]->url() === self::BASE.'/orders')->count());
    }

    public function test_failed_confirmation_is_flagged_and_can_be_retried(): void
    {
        $this->fakeBilling([self::BASE.'/orders/*/confirm-payment' => Http::sequence()
            ->push(['error' => ['code' => 'server_error', 'message' => 'Down']], 503)->push(['error' => ['code' => 'server_error', 'message' => 'Down']], 503)->push(['error' => ['code' => 'server_error', 'message' => 'Down']], 503)
            ->push(['id' => self::ORD, 'payment_status' => 'paid'], 200)]);
        $order = $this->bankTransferCheckout();
        $admin = User::where('email', 'admin@musicdesk.test')->first();

        $this->actingAs($admin)->post(route('admin.orders.paid', $order))->assertRedirect();
        $this->assertSame('confirm_payment', $order->fresh()->meta['billing_action']);
        $this->actingAs($admin)->get('/admin/orders')->assertSee('Billing didn’t confirm this bank transfer yet');

        $this->actingAs($admin)->post(route('admin.orders.resync', $order))->assertRedirect()->assertSessionHas('toast', fn ($t) => str_contains($t, 'up to date'));
        $this->assertArrayNotHasKey('billing_action', $order->fresh()->meta);
    }

    public function test_refund_revokes_the_license_in_billing(): void
    {
        $this->fakeBilling();
        $order = $this->bankTransferCheckout();
        $admin = User::where('email', 'admin@musicdesk.test')->first();
        $this->actingAs($admin)->post(route('admin.orders.paid', $order));

        $this->actingAs($admin)->post(route('admin.orders.refund', $order), ['reference' => 'rfnd_42', 'reason' => '30-day money-back guarantee'])
            ->assertRedirect()->assertSessionHas('toast', fn ($t) => str_contains($t, 'license revoked'));

        Http::assertSent(fn (Request $r) => $r->url() === self::BASE.'/orders/'.self::ORD.'/refund'
            && $r->data() === ['refund_reference' => 'rfnd_42', 'reason' => '30-day money-back guarantee']);
        $order->refresh();
        $this->assertSame('refunded', $order->status);
        $this->assertSame('refunded', $order->invoice->status);
        $this->assertArrayNotHasKey('billing_action', $order->meta);
    }

    public function test_if_billing_is_down_at_sign_up_the_first_order_creates_the_customer(): void
    {
        $this->fakeBilling([self::BASE.'/customers' => Http::response(['error' => ['code' => 'server_error', 'message' => 'Down']], 503)]);
        $order = $this->bankTransferCheckout();

        Http::assertSent(fn (Request $r) => $r->url() === self::BASE.'/orders' && ! $r->hasHeader('X-Customer-Id')
            && $r->data()['customer']['id'] === null && $r->data()['customer']['company'] === 'Bassline Music Pvt Ltd' && $r->data()['customer']['country'] === 'IN');
        $this->assertSame(self::CUS, $order->organisation->fresh()->billing_org_id);
    }

    public function test_billing_outage_never_blocks_checkout(): void
    {
        Http::fake(['ipapi.co/*' => Http::response([], 429), self::BASE.'/*' => Http::response(['error' => ['code' => 'server_error', 'message' => 'Down']], 503)]);
        $order = $this->bankTransferCheckout();

        $this->assertSame('awaiting_payment', $order->status);
        $this->assertNull($order->billing_license_id);
        $this->assertNotEmpty($order->meta['billing_error']);
        $this->get(route('checkout.complete', $order))->assertOk()->assertSee('being set up');
    }

    public function test_billing_webhook_clears_cached_licenses_for_that_customer(): void
    {
        $this->fakeBilling();
        $order = $this->bankTransferCheckout();
        $this->get('/dashboard/licenses');   // cache filled
        $listCalls = fn () => collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), '/licenses?'))->count();
        $before = $listCalls();

        $body = json_encode(['id' => 'evt_1', 'type' => 'license.suspended', 'created_at' => now()->toIso8601String(), 'storefront' => 'musicdesk', 'customer_id' => self::CUS, 'data' => []]);
        $t = time();
        $this->call('POST', '/webhooks/wcc', [], [], [], $this->transformHeadersToServerVars(['WCC-Signature' => "t=$t,v1=".hash_hmac('sha256', "$t.$body", 'whsec_test'), 'Content-Type' => 'application/json']), $body)->assertOk()->assertJson(['ok' => true]);

        $this->get('/dashboard/licenses');
        $this->assertSame($before + 1, $listCalls(), 'licenses re-fetched after the webhook');

        // Wrong storefront → ignored; bad signature → 400.
        $other = json_encode(['id' => 'evt_2', 'storefront' => 'other', 'customer_id' => self::CUS]);
        $this->call('POST', '/webhooks/wcc', [], [], [], $this->transformHeadersToServerVars(['WCC-Signature' => "t=$t,v1=".hash_hmac('sha256', "$t.$other", 'whsec_test')]), $other)->assertJson(['ignored' => true]);
        $this->call('POST', '/webhooks/wcc', [], [], [], $this->transformHeadersToServerVars(['WCC-Signature' => "t=$t,v1=bad"]), $body)->assertStatus(400);
    }

    public function test_customer_details_are_patched_once_the_customer_exists(): void
    {
        $this->fakeBilling([self::BASE.'/customers/*' => Http::response(['id' => self::CUS])]);
        $this->bankTransferCheckout();

        $this->post('/dashboard/billing/details', ['name' => 'Bassline Music', 'email' => 'accounts@bassline.test', 'country' => 'IN', 'vat_id' => '', 'address' => "12 MG Road\nBengaluru 560001"])->assertRedirect();

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->url() === self::BASE.'/customers/'.self::CUS
            && $r->data()['company'] === 'Bassline Music' && $r->data()['billing_email'] === 'accounts@bassline.test'
            && $r->data()['address'] === ['line1' => '12 MG Road, Bengaluru 560001']);
    }

    public function test_registration_creates_the_customer_in_billing(): void
    {
        $this->fakeBilling([self::BASE.'/licenses*' => Http::response(['data' => [], 'next_cursor' => null])]);
        $this->post('/register', ['name' => 'Ada', 'company' => 'Analytical Records', 'email' => 'ada@a.test', 'country' => 'GB', 'password' => 'correct-horse-battery'])->assertRedirect('/dashboard');

        $org = Organisation::where('name', 'Analytical Records')->firstOrFail();
        $this->assertSame(self::CUS, $org->billing_org_id);
        $this->assertNotNull($org->billing_synced_at);
        Http::assertSent(fn (Request $r) => $r->url() === self::BASE.'/customers' && $r->hasHeader('Idempotency-Key', 'customer-'.$org->id)
            && $r->data()['company'] === 'Analytical Records' && $r->data()['country'] === 'GB' && $r->hasHeader('X-Acting-User', 'ada@a.test'));
        $this->get('/dashboard/licenses')->assertOk()->assertSee('No licenses yet');
    }
}
