<?php

namespace App\Http\Controllers;

use App\Licensing\LicensePricer;
use App\Models\Addon;
use App\Models\License;
use App\Models\Order;
use App\Models\Plan;
use App\Payments\GatewayManager;
use App\Payments\PaymentAction;
use App\Services\Currency\CurrencyService;
use App\Services\Orders\OrderFulfiller;
use App\Services\Orders\OrderPricer;
use App\Services\Orders\OrganisationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class CheckoutController extends Controller
{
    public function __construct(
        private CurrencyService $currencies,
        private OrderPricer $pricer,
        private GatewayManager $gateways,
        private OrderFulfiller $fulfiller,
    ) {}

    public function show(Request $request, string $plan): View
    {
        $plan = Plan::active()->where('code', $plan)->firstOrFail();
        $billing = $request->query('billing') === 'lifetime' && $plan->price_lifetime ? 'lifetime' : 'annual';
        $currency = $this->currencies->current();
        $org = $request->user()?->organisation;

        return view('marketing.checkout', [
            'plan' => $plan,
            'billing' => $billing,
            'domains' => max(0, min(50, (int) $request->query('domains', 0))),
            'addons' => Addon::active()->where('checkout', true)->get(),
            'gateways' => $this->gateways->availableFor($currency),
            'currency' => $currency,
            'org' => $org,
            'quote' => $this->pricer->quote($plan, $billing, max(0, (int) $request->query('domains', 0)), [], $currency, $org?->country, $org?->vat_id),
        ]);
    }

    /** Live re-quote for the checkout summary (country/VAT/coupon/add-on changes). */
    public function quote(Request $request, string $plan)
    {
        $plan = Plan::active()->where('code', $plan)->firstOrFail();
        try {
            $q = $this->pricer->quote($plan, $request->input('billing', 'annual'), max(0, (int) $request->input('domains', 0)),
                (array) $request->input('addons', []), $this->currencies->current(), $request->input('country'), $request->input('vat_id'), $request->input('coupon'));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        $fmt = fn ($v) => $this->currencies->format($v, $q['currency'], true);

        return response()->json([
            'lines' => array_map(fn ($l) => ['description' => $l['description'], 'quantity' => $l['quantity'], 'amount' => $fmt($l['amount'])], $q['lines']),
            'subtotal' => $fmt($q['subtotal']), 'discount' => $q['discount'] > 0 ? '−'.$fmt($q['discount']) : null,
            'tax' => $fmt($q['tax']), 'tax_rate' => round($q['tax_rate'] * 100, 2), 'total' => $fmt($q['total']), 'coupon' => $q['coupon'],
            'coupon_invalid' => $request->filled('coupon') && ! $q['coupon'],
        ]);
    }

    public function store(Request $request, OrganisationService $orgs, string $plan): RedirectResponse|View
    {
        $plan = Plan::active()->where('code', $plan)->firstOrFail();
        $currency = $this->currencies->current();
        $guest = ! $request->user();

        $data = $request->validate([
            'billing' => ['required', Rule::in(['annual', 'lifetime'])],
            'domains' => ['nullable', 'integer', 'min:0', 'max:50'],
            'addons' => ['array'], 'addons.*' => ['string'],
            'name' => [$guest ? 'required' : 'nullable', 'string', 'max:120'],
            'email' => array_filter([$guest ? 'required' : 'nullable', 'email', 'max:190', $guest ? 'unique:users,email' : null]),
            'password' => [$guest ? 'required' : 'nullable', Password::min(10)],
            'company' => ['required', 'string', 'max:160'],
            'country' => ['required', 'string', 'size:2'],
            'vat_id' => ['nullable', 'string', 'max:40'],
            'primary_domain' => ['nullable', 'string', 'max:190', 'regex:/^(?!-)[a-z0-9.-]+\.[a-z]{2,}$/i'],
            'coupon' => ['nullable', 'string', 'max:40'],
            'gateway' => ['required', 'string'],
            'terms' => ['accepted'],
        ], ['email.unique' => 'An account with this email exists. Log in first, then buy.', 'primary_domain.regex' => 'Enter a domain like portal.yourlabel.com']);

        $gateway = $this->gateways->availableFor($currency)->first(fn ($g) => $g->code() === $data['gateway']);
        if (! $gateway) {
            return back()->withInput()->withErrors(['gateway' => 'That payment method isn’t available for '.$currency.'.']);
        }

        // Organisation: create for new customers; update details for existing ones.
        if ($guest) {
            $user = $orgs->register($data + ['company' => $data['company']]);
            Auth::login($user);
            $request->session()->regenerate();
        } else {
            $user = $request->user();
            $user->organisation->update(['name' => $data['company'], 'country' => strtoupper($data['country']), 'vat_id' => $data['vat_id'] ?? null]);
        }
        $org = $user->organisation;

        try {
            $q = $this->pricer->quote($plan, $data['billing'], (int) ($data['domains'] ?? 0), $data['addons'] ?? [], $currency, $org->country, $org->vat_id, $data['coupon'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['billing' => $e->getMessage()]);
        }

        $order = DB::transaction(fn () => Order::create([
            'number' => 'ORD-'.now()->format('ymd').'-'.Str::upper(Str::random(5)),
            'organisation_id' => $org->id,
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'kind' => 'new_license',
            'billing_type' => $data['billing'],
            'extra_domains' => (int) ($data['domains'] ?? 0),
            'lines' => $q['lines'],
            'currency' => $q['currency'],
            'exchange_rate' => $q['rate'],
            'subtotal' => $q['subtotal'],
            'discount' => $q['discount'],
            'tax_rate' => $q['tax_rate'],
            'tax' => $q['tax'],
            'total' => $q['total'],
            'coupon' => $q['coupon'],
            'gateway' => $gateway->code(),
            'status' => 'pending',
            'primary_domain' => isset($data['primary_domain']) ? strtolower($data['primary_domain']) : null,
            'meta' => ['addons' => $data['addons'] ?? []],
        ]));

        return $this->startPayment($order);
    }

    // ------------------------------------------------------------------
    // Changes to an existing license: renew, renew updates, extra domains,
    // upgrade, switch to lifetime. Same gateways, invoices and fulfilment.
    // ------------------------------------------------------------------

    public function licenseShow(Request $request, License $license, string $kind): View|RedirectResponse
    {
        $this->authorizeLicense($request, $license);
        abort_unless(isset(Order::KINDS[$kind]) && $kind !== 'new_license', 404);
        $pricer = app(LicensePricer::class);
        $options = $pricer->options($license->load('plan', 'organisation'));
        if ($options[$kind]) {
            return redirect()->route('dashboard.licenses')->with(['toast' => $options[$kind], 'toast_tone' => 'danger']);
        }

        $currency = $this->currencies->current();
        $upgrades = Plan::active()->where('price_annual', '>', $license->plan->price_annual)->get();
        $input = $this->licenseInput($request, $kind, $upgrades);

        return view('checkout.license', [
            'license' => $license,
            'kind' => $kind,
            'upgrades' => $upgrades,
            'input' => $input,
            'quote' => $pricer->quote($license, $kind, $input, $currency),
            'gateways' => $this->gateways->availableFor($currency),
            'currency' => $currency,
        ]);
    }

    public function licenseQuote(Request $request, License $license, string $kind)
    {
        $this->authorizeLicense($request, $license);
        try {
            $q = app(LicensePricer::class)->quote($license, $kind, $this->licenseInput($request, $kind, Plan::active()->get()), $this->currencies->current());
        } catch (\App\Licensing\LicenseException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        $fmt = fn ($v) => $this->currencies->format($v, $q['currency'], true);

        return response()->json([
            'lines' => array_map(fn ($l) => ['description' => $l['description'], 'quantity' => $l['quantity'], 'amount' => $fmt($l['amount'])], $q['lines']),
            'subtotal' => $fmt($q['subtotal']), 'tax' => $fmt($q['tax']), 'tax_rate' => round($q['tax_rate'] * 100, 2), 'total' => $fmt($q['total']),
        ]);
    }

    public function licenseStore(Request $request, License $license, string $kind): RedirectResponse|View
    {
        $this->authorizeLicense($request, $license);
        abort_unless(isset(Order::KINDS[$kind]) && $kind !== 'new_license', 404);
        $request->validate(['gateway' => ['required', 'string'], 'terms' => ['accepted'], 'quantity' => ['nullable', 'integer', 'min:1', 'max:50'], 'plan' => ['nullable', 'string']]);

        $currency = $this->currencies->current();
        $gateway = $this->gateways->availableFor($currency)->first(fn ($g) => $g->code() === $request->input('gateway'));
        if (! $gateway) {
            return back()->withErrors(['gateway' => 'That payment method isn’t available for '.$currency.'.']);
        }

        try {
            $q = app(LicensePricer::class)->quote($license->load('plan', 'organisation'), $kind, $this->licenseInput($request, $kind, Plan::active()->get()), $currency);
        } catch (\App\Licensing\LicenseException $e) {
            return back()->withErrors(['purchase' => $e->getMessage()]);
        }

        $order = Order::create([
            'number' => 'ORD-'.now()->format('ymd').'-'.Str::upper(Str::random(5)),
            'organisation_id' => $license->organisation_id,
            'user_id' => $request->user()->id,
            'plan_id' => $q['plan_id'],
            'license_id' => $license->id,
            'kind' => $kind,
            'billing_type' => $kind === 'convert_lifetime' ? 'lifetime' : $license->billing_type,
            'extra_domains' => $q['extra_domains'],
            'lines' => $q['lines'],
            'currency' => $q['currency'],
            'exchange_rate' => $q['rate'],
            'subtotal' => $q['subtotal'],
            'discount' => 0,
            'tax_rate' => $q['tax_rate'],
            'tax' => $q['tax'],
            'total' => $q['total'],
            'gateway' => $gateway->code(),
            'status' => 'pending',
        ]);

        return $this->startPayment($order);
    }

    /** @return array{quantity: int, plan: ?Plan} */
    private function licenseInput(Request $request, string $kind, $plans): array
    {
        return [
            'quantity' => max(1, min(50, (int) $request->input('quantity', 1))),
            'plan' => $kind === 'change_plan' ? ($plans->firstWhere('code', $request->input('plan')) ?? $plans->first()) : null,
        ];
    }

    private function authorizeLicense(Request $request, License $license): void
    {
        abort_unless($request->user() && $request->user()->organisation_id === $license->organisation_id, 404);
        abort_unless(in_array($request->user()->role, ['owner', 'admin', 'billing'], true), 403, 'Ask an owner, admin or billing member to make purchases.');
    }

    /** Hand the order to its gateway. */
    private function startPayment(Order $order): RedirectResponse|View
    {
        $gateway = $this->gateways->driver($order->gateway);
        $urls = [
            'return' => route('checkout.return', $order),
            'verify' => route('checkout.verify', $order),
            'cancel' => route('checkout.cancel', $order),
        ];

        try {
            $action = $gateway->createPayment($order->load('organisation', 'user'), $urls);
        } catch (\Throwable $e) {
            Log::error('Payment start failed', ['order' => $order->number, 'gateway' => $order->gateway, 'error' => $e->getMessage()]);
            $order->update(['status' => 'failed']);

            return redirect($this->backUrl($order))->withErrors(['gateway' => 'We couldn’t start the payment with '.$gateway->name().'. Please try again or pick another method.']);
        }

        if ($action->gatewayOrderId) {
            $order->update(['gateway_order_id' => $action->gatewayOrderId]);
        }

        return match ($action->type) {
            PaymentAction::REDIRECT => redirect()->away($action->redirectUrl),
            PaymentAction::SCRIPT => view('checkout.pay', ['order' => $order, 'gateway' => $gateway, 'payload' => $action->payload]),
            PaymentAction::OFFLINE => tap(redirect()->route('checkout.complete', $order), fn () => $this->fulfiller->awaitOffline($order)),
        };
    }

    /** Retry payment for an unpaid order (e.g. Razorpay modal closed). */
    public function pay(Request $request, Order $order): RedirectResponse|View
    {
        $this->authorizeOrder($request, $order);
        abort_if($order->isPaid(), 409, 'This order is already paid.');

        return $this->startPayment($order);
    }

    /** JS-widget gateways (Razorpay) post their result here. */
    public function verify(Request $request, Order $order): RedirectResponse
    {
        $this->authorizeOrder($request, $order);

        return $this->settle($request, $order);
    }

    /** Redirect gateways (Stripe, PayPal) send the customer back here. */
    public function return(Request $request, Order $order): RedirectResponse
    {
        $this->authorizeOrder($request, $order);

        return $this->settle($request, $order);
    }

    private function settle(Request $request, Order $order): RedirectResponse
    {
        if (! $order->isPaid()) {
            $result = $this->gateways->driver($order->gateway)->verifyReturn($order, $request);
            if (! $result->paid) {
                $order->update(['status' => 'failed']);

                return redirect()->route('checkout.complete', $order)->withErrors(['payment' => $result->message]);
            }
            $this->fulfiller->markPaid($order, $result->paymentId);
        }

        return redirect()->route('checkout.complete', $order);
    }

    public function cancel(Request $request, Order $order): RedirectResponse
    {
        $this->authorizeOrder($request, $order);
        if (! $order->isPaid()) {
            $order->update(['status' => 'cancelled']);
        }

        return redirect($this->backUrl($order))->with('toast', 'Payment cancelled. Nothing was charged.');
    }

    /** Where to send the customer back to: the plan checkout, or the license purchase page. */
    private function backUrl(Order $order): string
    {
        return $order->isNewLicense() || ! $order->license
            ? route('checkout', $order->plan?->code ?? 'starter')
            : route('licenses.buy', [$order->license, $order->kind]);
    }

    public function complete(Request $request, Order $order): View
    {
        $this->authorizeOrder($request, $order);

        return view('marketing.success', [
            'order' => $order->load('plan', 'invoice', 'license.plan'),
            'bank' => $order->gateway === 'bank_transfer' ? $this->gateways->driver('bank_transfer')->details() : null,
        ]);
    }

    private function authorizeOrder(Request $request, Order $order): void
    {
        abort_unless($request->user() && $request->user()->organisation_id === $order->organisation_id, 404);
    }
}
