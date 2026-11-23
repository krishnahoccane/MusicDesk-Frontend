<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Licensing\ActivationService;
use App\Licensing\LicenseException;
use App\Licensing\LicenseService;
use App\Licensing\SecurityAlerts;
use App\Models\Currency;
use App\Models\License;
use App\Models\LicenseActivation;
use App\Models\LicenseAddon;
use App\Models\LicenseAlert;
use App\Models\Order;
use App\Models\Organisation;
use App\Models\Plan;
use App\Services\Orders\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Licenses: list, issue by hand, and every change (plan, domains, dates, suspend / reinstate / revoke, key). */
class LicenseAdminController extends Controller
{
    public function __construct(private LicenseService $licenses, private ActivationService $activations) {}

    private function actor(Request $request): string
    {
        return LicenseService::actor($request->user());
    }

    public function index(Request $request): View
    {
        $status = $request->query('status');
        $now = now();

        $query = License::query()->with(['organisation', 'plan', 'addon', 'parent'])->withCount(['activations as domains_live' => fn ($q) => $q->where('status', '!=', LicenseActivation::DEACTIVATED)])
            ->search($request->query('q'))
            ->when($request->query('plan'), fn ($q, $p) => $q->whereHas('plan', fn ($w) => $w->where('code', $p)))
            ->when($status === 'expiring', fn ($q) => $q->where('status', License::ACTIVE)->whereBetween('expires_at', [$now, $now->copy()->addDays(License::EXPIRING_DAYS)]))
            ->when($status === 'expired', fn ($q) => $q->where(fn ($w) => $w->where(fn ($a) => $a->where('status', License::ACTIVE)->where('expires_at', '<', $now))
                ->orWhere(fn ($p) => $p->where('status', License::PENDING)->where('pending_until', '<', $now))))
            ->when(in_array($status, [License::PENDING, License::ACTIVE, License::SUSPENDED, License::REVOKED], true), fn ($q) => $q->where('status', $status));

        return view('admin.licenses.index', [
            'licenses' => $query->latest('id')->paginate(25)->withQueryString(),
            'plans' => Plan::orderBy('sort')->get(),
            'stats' => [
                'total' => License::count(),
                'active' => License::where('status', License::ACTIVE)->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now))->count(),
                'expiring' => License::where('status', License::ACTIVE)->whereBetween('expires_at', [$now, $now->copy()->addDays(30)])->count(),
                'pending' => License::where('status', License::PENDING)->count(),
                'suspended' => License::whereIn('status', [License::SUSPENDED, License::REVOKED])->count(),
                'installs' => LicenseActivation::where('status', LicenseActivation::ACTIVE)->where('last_check_in_at', '>', $now->copy()->subHours(LicenseActivation::ONLINE_HOURS))->count(),
            ],
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.licenses.create', [
            'organisations' => Organisation::orderBy('name')->get(['id', 'name', 'email']),
            'plans' => Plan::orderBy('sort')->get(),
            'currencies' => Currency::enabled()->pluck('code'),
            'selected' => $request->query('organisation'),
            'domain' => $request->query('domain'),
            'alert' => $request->query('alert') ? LicenseAlert::find($request->query('alert')) : null,
        ]);
    }

    /** Manual sale, partner/comp license or replacement. Optionally records the sale (order + invoice). */
    public function store(Request $request, InvoiceService $invoices): RedirectResponse
    {
        $data = $request->validate([
            'organisation_id' => ['nullable', 'integer', 'exists:organisations,id'],
            'new_org_name' => ['required_without:organisation_id', 'nullable', 'string', 'max:160'],
            'new_org_email' => ['required_without:organisation_id', 'nullable', 'email', 'max:190'],
            'new_org_country' => ['required_without:organisation_id', 'nullable', 'string', 'size:2'],
            'plan_id' => ['required', 'exists:plans,id'],
            'billing_type' => ['required', Rule::in(['annual', 'lifetime'])],
            'domains_extra' => ['nullable', 'integer', 'min:0', 'max:500'],
            'status' => ['required', Rule::in(['active', 'pending'])],
            'primary_domain' => ['nullable', 'string', 'max:253'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'record_sale' => ['nullable', 'boolean'],
            'amount' => ['required_if:record_sale,1', 'nullable', 'numeric', 'min:0'],
            'currency' => ['required_if:record_sale,1', 'nullable', 'string', 'size:3'],
            'payment' => ['nullable', Rule::in(['bank_transfer', 'manual'])],
            'paid' => ['nullable', 'boolean'],
            'alert_id' => ['nullable', 'integer', 'exists:license_alerts,id'],
        ]);

        $actor = $this->actor($request);
        [$license, $key] = DB::transaction(function () use ($data, $actor, $invoices, $request) {
            $org = ! empty($data['organisation_id']) ? Organisation::findOrFail($data['organisation_id']) : Organisation::create([
                'name' => $data['new_org_name'], 'email' => $data['new_org_email'], 'country' => strtoupper($data['new_org_country']),
                'currency' => strtoupper($data['currency'] ?? 'USD'),
            ]);
            $plan = Plan::findOrFail($data['plan_id']);
            $amount = $request->boolean('record_sale') ? (float) $data['amount'] : 0.0;
            $currency = strtoupper($data['currency'] ?? 'USD');

            [$license, $key] = $this->licenses->create($org, $plan, [
                'billing_type' => $data['billing_type'], 'domains_extra' => (int) ($data['domains_extra'] ?? 0),
                'price_paid' => $amount, 'currency' => $currency, 'pending' => $data['status'] === 'pending', 'notes' => $data['notes'] ?? null,
            ], $actor);

            if ($request->boolean('record_sale')) {
                $paid = $request->boolean('paid');
                $order = Order::create([
                    'number' => 'ORD-'.now()->format('ymd').'-'.Str::upper(Str::random(5)),
                    'organisation_id' => $org->id, 'plan_id' => $plan->id, 'license_id' => $license->id, 'kind' => 'new_license',
                    'billing_type' => $data['billing_type'], 'extra_domains' => (int) ($data['domains_extra'] ?? 0),
                    'lines' => [['description' => setting('branding.product_name')." {$plan->name}: ".($data['billing_type'] === 'lifetime' ? 'lifetime' : 'annual').' license', 'quantity' => 1, 'unit' => $amount, 'amount' => $amount]],
                    'currency' => $currency, 'exchange_rate' => Currency::find($currency)?->effectiveRate() ?? 1,
                    'subtotal' => $amount, 'discount' => 0, 'tax_rate' => 0, 'tax' => 0, 'total' => $amount,
                    'gateway' => $data['payment'] ?? 'manual', 'status' => $paid ? 'paid' : 'awaiting_payment', 'paid_at' => $paid ? now() : null,
                    'gateway_payment_id' => $paid ? 'manual-'.now()->format('YmdHis') : null, 'meta' => ['issued_by' => $actor],
                ]);
                $invoices->forOrder($order);
            }

            return [$license, $key];
        });

        // Issued from a security alert (Licenses > Status): the alert is settled and its domain unblocked.
        if (! empty($data['alert_id'])) {
            app(SecurityAlerts::class)->resolve(LicenseAlert::findOrFail($data['alert_id']), LicenseAlert::ISSUED, $actor, $license, "Issued {$license->number}");
        }

        if (! empty($data['primary_domain'])) {
            try {
                $this->activations->add($license, $data['primary_domain'], null, $actor);
            } catch (LicenseException $e) {
                return redirect()->route('admin.licenses.show', $license)->with(['new_key' => $key, 'toast' => 'License created, but the domain wasn’t added: '.$e->getMessage(), 'toast_tone' => 'danger']);
            }
        }

        return redirect()->route('admin.licenses.show', $license)->with(['new_key' => $key, 'toast' => "{$license->number} created"]);
    }

    public function show(License $license): View
    {
        $license->load(['organisation.users', 'plan', 'addon', 'parent', 'activations' => fn ($q) => $q->orderByRaw("case status when 'deactivated' then 1 else 0 end")->latest('id'), 'orders.invoice',
            'addonLicenses' => fn ($q) => $q->with('addon')->withCount(['activations as domains_live' => fn ($a) => $a->where('status', '!=', LicenseActivation::DEACTIVATED)])]);

        return view('admin.licenses.show', [
            'license' => $license,
            'availableAddons' => $license->isAddon() ? collect() : app(\App\Licensing\LicensePricer::class)->availableAddons($license),
            'events' => $license->events()->limit(100)->get(),
            'plans' => Plan::orderBy('sort')->get(),
        ]);
    }

    /** Give a main license an add-on by hand (comp, manual sale): its own key, shown once. */
    public function issueAddon(Request $request, License $license): RedirectResponse
    {
        $data = $request->validate(['addon_id' => ['required', 'exists:license_addons,id'], 'billing_type' => ['required', Rule::in(['annual', 'lifetime'])], 'notes' => ['nullable', 'string', 'max:500']]);
        try {
            [$addonLicense, $key] = $this->licenses->createAddon($license, LicenseAddon::findOrFail($data['addon_id']), false, [
                'billing_type' => $data['billing_type'], 'price_paid' => 0, 'currency' => $license->currency, 'notes' => $data['notes'] ?? null,
            ], $this->actor($request));
        } catch (LicenseException $e) {
            return back()->with(['toast' => $e->getMessage(), 'toast_tone' => 'danger']);
        }

        return redirect()->route('admin.licenses.show', $addonLicense)->with(['new_key' => $key, 'toast' => "{$addonLicense->number} issued"]);
    }

    public function update(Request $request, License $license): RedirectResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'exists:plans,id'],
            'domains_extra' => ['required', 'integer', 'min:0', 'max:500'],
            'expires_at' => ['nullable', 'date'],
            'support_until' => ['nullable', 'date'],
            'max_version' => ['nullable', 'string', 'max:20', 'regex:/^\d+\.\d+\.\d+([.-][0-9A-Za-z.-]+)?$/'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $data['auto_renew'] = $request->boolean('auto_renew');
        $data['expires_at'] = $license->billing_type === 'annual' && ! empty($data['expires_at']) ? Carbon::parse($data['expires_at'])->endOfDay() : $license->expires_at;
        $data['support_until'] = ! empty($data['support_until']) ? Carbon::parse($data['support_until'])->endOfDay() : $license->support_until;
        $data['max_version'] = $data['max_version'] ?: null;

        $this->licenses->update($license, $data, $this->actor($request));

        return back()->with('toast', 'License updated');
    }

    public function status(Request $request, License $license): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'suspended', 'revoked'])],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'confirm' => [$request->input('status') === 'revoked' ? 'required' : 'nullable', 'string'],
        ]);
        if ($data['status'] === 'revoked' && strtoupper(trim((string) $data['confirm'])) !== $license->number) {
            return back()->withErrors(['confirm' => "Type {$license->number} to confirm revoking."]);
        }

        try {
            $this->licenses->setStatus($license, $data['status'], $data['reason'], $this->actor($request));
        } catch (LicenseException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        $done = ['active' => 'reinstated', 'suspended' => 'suspended', 'revoked' => 'revoked'][$data['status']];

        return back()->with('toast', "{$license->number} $done. Installs pick it up at their next check-in.");
    }

    public function reveal(Request $request, License $license): JsonResponse
    {
        return response()->json(['key' => $this->licenses->revealKey($license, $this->actor($request))])->header('Cache-Control', 'no-store');
    }

    public function regenerate(Request $request, License $license): RedirectResponse
    {
        $key = $this->licenses->regenerateKey($license, $this->actor($request));

        return back()->with(['new_key' => $key, 'toast' => 'New key generated. Give it to the customer; the old key stops working at the next check-in.']);
    }

    public function addDomain(Request $request, License $license): RedirectResponse
    {
        $data = $request->validate(['domain' => ['required', 'string', 'max:253'], 'environment' => ['nullable', Rule::in(['production', 'staging'])]]);
        try {
            $a = $this->activations->add($license, $data['domain'], $data['environment'] ?? null, $this->actor($request));
        } catch (LicenseException $e) {
            return back()->withErrors(['domain' => $e->getMessage()])->withInput();
        }

        return back()->with('toast', "{$a->domain} added");
    }

    public function deactivateDomain(Request $request, License $license, LicenseActivation $activation): RedirectResponse
    {
        abort_unless($activation->license_id === $license->id, 404);
        $this->activations->deactivate($activation, $this->actor($request));

        return back()->with('toast', "{$activation->domain} deactivated");
    }

    public function checkIn(Request $request, License $license, LicenseActivation $activation): RedirectResponse
    {
        abort_unless($activation->license_id === $license->id, 404);
        $this->activations->requestCheckIn($activation, $this->actor($request));

        return back()->with('toast', "Check-in requested from {$activation->domain}");
    }
}
