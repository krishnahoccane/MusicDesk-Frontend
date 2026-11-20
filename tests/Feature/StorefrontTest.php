<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\PaymentGateway;
use App\Models\User;
use App\Services\Orders\InvoiceService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StorefrontTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Http::preventStrayRequests();   // no real calls to geo / rate / gateway APIs
        Http::fake(['ipapi.co/*' => Http::response(['error' => true], 429)]);
    }

    public function test_public_pages_render(): void
    {
        foreach (['/', '/pricing', '/contact', '/legal/terms', '/legal/privacy', '/checkout/business', '/login', '/register'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/legal/nope')->assertNotFound();
    }

    public function test_prices_show_in_the_selected_currency_with_charm_rounding(): void
    {
        $this->post('/locale/currency', ['currency' => 'INR'])->assertRedirect();
        $this->get('/pricing')->assertOk()->assertSee('₹45,999')->assertSee('₹46,227');

        $this->post('/locale/currency', ['currency' => 'GBP']);
        $this->get('/pricing')->assertSee('£379');

        $this->post('/locale/currency', ['currency' => 'EUR']);
        $this->get('/pricing')->assertSee('€449');
    }

    public function test_currency_defaults_to_usd(): void
    {
        $this->get('/pricing')->assertSee('$499');
    }

    public function test_registration_creates_the_organisation_here_and_in_billing(): void
    {
        $this->post('/register', [
            'name' => 'Ada Lovelace', 'company' => 'Analytical Records', 'email' => 'ada@analytical.test', 'country' => 'GB', 'password' => 'correct-horse-battery',
        ])->assertRedirect('/dashboard');

        $user = User::where('email', 'ada@analytical.test')->firstOrFail();
        $this->assertSame('Analytical Records', $user->organisation->name);
        $this->assertSame('GB', $user->organisation->country);
        $this->assertStringStartsWith('cus_', $user->organisation->billing_org_id, 'created in billing at sign-up (POST /customers)');
        $this->assertSame('owner', $user->role);
        $this->get('/dashboard')->assertOk()->assertSee('Get your first');

        $this->post('/logout');
        $this->post('/register', ['name' => 'X', 'company' => 'Y', 'email' => 'x@y.test', 'password' => 'correct-horse-battery'])->assertSessionHasErrors('country');
    }

    public function test_guest_checkout_with_bank_transfer_issues_invoice_and_license(): void
    {
        $this->post('/locale/currency', ['currency' => 'INR']);

        $response = $this->post('/checkout/starter', [
            'billing' => 'annual', 'domains' => 1, 'addons' => ['installation'],
            'name' => 'Ravi Kumar', 'email' => 'ravi@bassline.test', 'password' => 'very-secure-pass',
            'company' => 'Bassline Music Pvt Ltd', 'country' => 'IN', 'vat_id' => '',
            'primary_domain' => 'portal.bassline.test', 'gateway' => 'bank_transfer', 'terms' => '1',
        ]);

        $order = Order::latest('id')->firstOrFail();
        $response->assertRedirect(route('checkout.complete', $order));

        // Server-side pricing in INR: ₹45,999 license + ₹13,799 domain + ₹31,999 install, 18% GST for India.
        $this->assertSame('INR', $order->currency);
        $this->assertEquals(45999 + 13799 + 31999, $order->subtotal);
        $this->assertEqualsWithDelta(0.18, $order->tax_rate, 0.0001);
        $this->assertEquals(round(91797 * 1.18, 2), $order->total);
        $this->assertSame('awaiting_payment', $order->status);
        $this->assertNotNull($order->license_key);
        $this->assertStringStartsWith('LDSK-', $order->license_key);
        $this->assertStringStartsWith('cus_', $order->organisation->fresh()->billing_org_id, 'customer created in billing with the first order');

        $invoice = $order->invoice;
        $this->assertSame('open', $invoice->status);
        $this->assertMatchesRegularExpression('/^MD-\d{4}-\d{6}$/', $invoice->number);
        $this->assertSame('Bassline Music Pvt Ltd', $invoice->buyer['name']);

        $this->get(route('checkout.complete', $order))->assertOk()->assertSee('Awaiting payment')->assertSee('Bank transfer details');
        $this->get(route('dashboard.invoices.pdf', $invoice))->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_admin_marks_bank_transfer_paid(): void
    {
        $this->test_guest_checkout_with_bank_transfer_issues_invoice_and_license();
        $order = Order::latest('id')->first();

        $admin = User::where('email', 'admin@musicdesk.test')->first();
        $this->actingAs($admin)->post(route('admin.orders.paid', $order), ['reference' => 'NEFT123'])->assertRedirect();

        $order->refresh();
        $this->assertSame('paid', $order->status);
        $this->assertSame('NEFT123', $order->gateway_payment_id);
        $this->assertSame('paid', $order->invoice->status);
    }

    public function test_razorpay_webhook_marks_order_paid_once(): void
    {
        $gw = PaymentGateway::find('razorpay');
        $gw->update(['enabled' => true, 'credentials' => ['key_id' => 'rzp_test_x', 'key_secret' => 'sec', 'webhook_secret' => 'whsec']]);

        $user = User::where('email', 'demo@lowtide.test')->first();
        $order = Order::create([
            'number' => 'ORD-TEST-1', 'organisation_id' => $user->organisation_id, 'user_id' => $user->id, 'plan_id' => 1,
            'lines' => [['description' => 'LabelDesk Starter', 'quantity' => 1, 'unit' => 45999, 'amount' => 45999]],
            'currency' => 'INR', 'exchange_rate' => 92.64, 'subtotal' => 45999, 'total' => 45999,
            'gateway' => 'razorpay', 'gateway_order_id' => 'order_RZP1', 'status' => 'pending',
        ]);

        $body = json_encode(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => ['id' => 'pay_1', 'order_id' => 'order_RZP1']]]]);
        $headers = ['X-Razorpay-Signature' => hash_hmac('sha256', $body, 'whsec'), 'X-Razorpay-Event-Id' => 'evt_1', 'Content-Type' => 'application/json'];

        $this->call('POST', '/webhooks/payments/razorpay', [], [], [], $this->transformHeadersToServerVars($headers), $body)->assertOk();
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(1, Invoice::where('order_id', $order->id)->count());

        // Duplicate delivery is ignored.
        $this->call('POST', '/webhooks/payments/razorpay', [], [], [], $this->transformHeadersToServerVars($headers), $body)->assertJson(['duplicate' => true]);
        $this->assertSame(1, Invoice::where('order_id', $order->id)->count());

        // Bad signature is rejected.
        $this->call('POST', '/webhooks/payments/razorpay', [], [], [], $this->transformHeadersToServerVars(['X-Razorpay-Signature' => 'nope']), $body)->assertStatus(400);
    }

    public function test_only_configured_gateways_for_the_currency_are_offered(): void
    {
        PaymentGateway::find('paypal')->update(['enabled' => true, 'credentials' => ['client_id' => 'a', 'client_secret' => 'b']]);

        $this->get('/checkout/starter')->assertSee('PayPal');           // USD: supported
        $this->post('/locale/currency', ['currency' => 'INR']);
        $this->get('/checkout/starter')->assertDontSee('>PayPal<', false); // INR: PayPal not offered
    }

    public function test_invoice_numbers_are_sequential_per_year(): void
    {
        $service = app(InvoiceService::class);
        $year = now()->format('Y');
        $last = Invoice::where('number', 'like', "MD-$year-%")->max('number');
        $expected = $last ? (int) substr($last, -6) + 1 : 1;

        $this->assertSame(sprintf('MD-%s-%06d', $year, $expected), $service->nextNumber());
    }

    public function test_portal_and_admin_access(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');

        $customer = User::where('email', 'demo@lowtide.test')->first();
        foreach (['/dashboard', '/dashboard/licenses', '/dashboard/downloads', '/dashboard/billing', '/dashboard/support', '/dashboard/settings'] as $url) {
            $this->actingAs($customer)->get($url)->assertOk();
        }
        $this->actingAs($customer)->get('/admin')->assertForbidden();

        $admin = User::where('email', 'admin@musicdesk.test')->first();
        foreach (['/admin', '/admin/settings/branding', '/admin/settings/home', '/admin/settings/contact', '/admin/settings/invoice', '/admin/settings/general', '/admin/plans', '/admin/currencies', '/admin/gateways', '/admin/pages', '/admin/orders', '/admin/invoices'] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
    }

    public function test_admin_branding_changes_the_theme(): void
    {
        $admin = User::where('email', 'admin@musicdesk.test')->first();
        $this->actingAs($admin)->post('/admin/settings/branding', [
            'site_name' => 'TWSD Music', 'site_suffix' => '', 'product_name' => 'LabelDesk',
            'color_primary' => '#ff8a3d', 'color_secondary' => '#ff3fd2', 'color_accent' => '#ffb547', 'color_success' => '#22d9a0', 'color_background' => '#07070b',
            'font_sans' => 'Manrope', 'font_serif' => 'Fraunces', 'font_mono' => 'JetBrains Mono',
        ])->assertRedirect();

        $this->get('/')->assertSee('--color-volt-500:#ff8a3d', false)->assertSee('TWSD Music')->assertSee('manrope', false);
    }

    public function test_time_zone_comes_from_the_browser_cookie_when_ip_is_local(): void
    {
        $customer = User::where('email', 'demo@lowtide.test')->first();
        $this->actingAs($customer)->withUnencryptedCookie('tz', 'Asia/Kolkata')->get('/dashboard')->assertSee('Asia/Kolkata');
        $this->actingAs($customer)->withUnencryptedCookie('tz', 'Not/AZone')->get('/dashboard')->assertSee('UTC');
    }
}
