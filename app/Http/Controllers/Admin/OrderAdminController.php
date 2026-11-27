<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Licensing\LicenseException;
use App\Licensing\LicensePricer;
use App\Licensing\LicenseService;
use App\Models\Currency;
use App\Models\License;
use App\Models\LicenseAddon;
use App\Models\Organisation;
use App\Models\Plan;
use App\Services\Orders\OrderPricer;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Models\Invoice;
use App\Models\Order;
use App\Services\Orders\OrderFulfiller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Orders & invoices. Paying an order applies it to the license (create, renew, upgrade…). */
class OrderAdminController extends Controller
{
    public function orders(Request $request): View
    {
        $orders = Order::with('organisation', 'plan', 'invoice', 'license')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('kind'), fn ($q, $k) => $q->where('kind', $k))
            ->latest()->paginate(25)->withQueryString();

        return view('admin.orders', [
            'orders' => $orders,
            // For "Create order": customers with their main licenses, plans, add-ons, currencies.
            'customers' => Organisation::orderBy('name')->with(['licenses' => fn ($q) => $q->core()->where('status', '!=', License::REVOKED)->with('plan')])
                ->get(['id', 'name', 'email', 'currency'])
                ->map(fn (Organisation $o) => ['id' => $o->id, 'name' => $o->name, 'email' => $o->email, 'currency' => $o->currency ?: 'USD',
                    'licenses' => $o->licenses->map(fn (License $l) => ['id' => $l->id, 'label' => $l->number.' · '.$l->plan?->name.' · '.ucfirst($l->billing_type)])->values()])->values(),
            'plans' => Plan::orderBy('sort')->get(['id', 'name', 'code']),
            'licenseAddons' => LicenseAddon::orderBy('sort')->orderBy('name')->get(['id', 'name', 'code']),
            'currencies' => Currency::enabled()->pluck('code'),
        ]);
    }

    /**
     * Create an order by hand (phone / email sale): any kind, at list price (with the customer's tax)
     * or a custom total. "Paid" applies it at once; otherwise it waits for payment like a bank transfer
     * (a new license is created as pending, an invoice is issued unpaid).
     */
    public function store(Request $request, OrderPricer $pricer, LicensePricer $licensePricer, OrderFulfiller $fulfiller): RedirectResponse
    {
        $data = $request->validate([
            'organisation_id' => ['required', 'integer', 'exists:organisations,id'],
            'kind' => ['required', Rule::in(array_keys(Order::KINDS))],
            'license_id' => ['required_unless:kind,new_license', 'nullable', 'integer', 'exists:licenses,id'],
            'plan_id' => ['required_if:kind,new_license', 'required_if:kind,change_plan', 'nullable', 'integer', 'exists:plans,id'],
            'billing' => ['nullable', 'in:annual,lifetime'],
            'quantity' => ['nullable', 'integer', 'min:0', 'max:50'],
            'addon_id' => ['required_if:kind,addon', 'nullable', 'integer', 'exists:license_addons,id'],
            'currency' => ['required', 'string', 'size:3'],
            'price' => ['required', 'in:list,custom'],
            'amount' => ['required_if:price,custom', 'nullable', 'numeric', 'min:0'],
            'gateway' => ['required', 'in:bank_transfer,manual'],
            'paid' => ['nullable', 'boolean'],
            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:500'],
        ], ['license_id.required_unless' => 'Choose the license this order is for.', 'amount.required_if' => 'Enter the total.']);

        $org = Organisation::findOrFail($data['organisation_id']);
        $currency = strtoupper($data['currency']);
        $kind = $data['kind'];
        $actor = LicenseService::actor($request->user());

        try {
            if ($kind === 'new_license') {
                $plan = Plan::findOrFail($data['plan_id']);
                $billing = $data['billing'] ?? 'annual';
                $extra = (int) ($data['quantity'] ?? 0);
                $q = $pricer->quote($plan, $billing, $extra, [], $currency, $org->country, $org->vat_id)
                    + ['plan_id' => $plan->id, 'billing_type' => $billing, 'extra_domains' => $extra, 'addon_id' => null, 'license_id' => null];
            } else {
                $license = License::query()->where('organisation_id', $org->id)->findOrFail($data['license_id']);
                $q = $licensePricer->quote($license->load('plan', 'organisation'), $kind, [
                    'quantity' => max(1, (int) ($data['quantity'] ?? 1)),
                    'plan' => $kind === 'change_plan' ? Plan::find($data['plan_id']) : null,
                    'addon' => $kind === 'addon' ? LicenseAddon::find($data['addon_id']) : null,
                    'billing' => $data['billing'] ?? 'annual',
                ], $currency);
                $q['license_id'] = $license->id;
            }
        } catch (LicenseException|\InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['order' => $e->getMessage()]);
        }

        if ($data['price'] === 'custom') {   // one line at the agreed total, no tax added
            $amount = round((float) $data['amount'], 2);
            $q['lines'] = [['code' => $kind, 'description' => collect($q['lines'])->pluck('description')->first() ?? Order::KINDS[$kind], 'quantity' => 1, 'unit' => $amount, 'amount' => $amount]];
            $q = array_merge($q, ['subtotal' => $amount, 'discount' => 0, 'tax_rate' => 0, 'tax' => 0, 'total' => $amount]);
        }

        $order = Order::create([
            'number' => 'ORD-'.now()->format('ymd').'-'.Str::upper(Str::random(5)),
            'organisation_id' => $org->id,
            'user_id' => $org->users()->where('role', 'owner')->value('id'),
            'plan_id' => $q['plan_id'],
            'license_id' => $q['license_id'],
            'kind' => $kind,
            'billing_type' => $q['billing_type'],
            'extra_domains' => $q['extra_domains'],
            'lines' => $q['lines'],
            'currency' => $q['currency'] ?? $currency,
            'exchange_rate' => $q['rate'] ?? 1,
            'subtotal' => $q['subtotal'],
            'discount' => $q['discount'] ?? 0,
            'tax_rate' => $q['tax_rate'] ?? 0,
            'tax' => $q['tax'] ?? 0,
            'total' => $q['total'],
            'gateway' => $data['gateway'],
            'status' => 'pending',
            'meta' => array_filter(['addon_id' => $q['addon_id'] ?? null, 'created_by' => $actor, 'note' => $data['note'] ?? null]),
        ]);

        $order = $request->boolean('paid')
            ? $fulfiller->markPaid($order, ($data['reference'] ?? null) ?: 'manual-'.now()->format('YmdHis'), $actor)
            : $fulfiller->awaitOffline($order);

        return redirect()->route('admin.orders')->with('toast', "Order {$order->number} created".($order->isPaid() ? ' and paid' : ', awaiting payment').($order->license ? " · {$order->license->number}" : ''));
    }

    public function invoices(): View
    {
        return view('admin.invoices', ['invoices' => Invoice::with('organisation')->latest('issued_at')->paginate(25)]);
    }

    /** Confirm a bank transfer: marks paid, settles the invoice, activates / applies the license change. */
    public function markPaid(Request $request, Order $order, OrderFulfiller $fulfiller): RedirectResponse
    {
        abort_if($order->isPaid(), 409);
        $fulfiller->markPaid($order, $request->input('reference') ?: 'manual-'.now()->format('YmdHis'), LicenseService::actor($request->user()));

        return back()->with('toast', "{$order->number} marked as paid".($order->fresh()->license ? ' and applied to '.$order->fresh()->license->number : ''));
    }

    /** Record a refund made in the gateway dashboard. A refunded new license is revoked. */
    public function refund(Request $request, Order $order, OrderFulfiller $fulfiller): RedirectResponse
    {
        abort_unless($order->isPaid(), 409);
        $data = $request->validate(['reference' => ['nullable', 'string', 'max:120'], 'reason' => ['nullable', 'string', 'max:500']]);
        $fulfiller->refund($order, $data, LicenseService::actor($request->user()));
        $order->refresh();

        $note = $order->license && $order->isNewLicense() ? ' and '.$order->license->number.' revoked' : ($order->license ? '. Review '.$order->license->number.' if the change should be undone' : '');

        return back()->with('toast', "{$order->number} refunded$note");
    }
}
