<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Plan;
use App\Services\Billing\BillingClient;
use App\Services\Orders\InvoiceService;
use App\Services\Orders\OrganisationService;
use App\Support\Demo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class PortalController extends Controller
{
    public function __construct(private BillingClient $billing) {}

    private function licenses(Request $request): array
    {
        $org = \App\Models\Organisation::find($request->user()->organisation_id);
        if (! $org) {
            return [];
        }
        try {
            return $this->billing->licenses($org);
        } catch (\Throwable $e) {
            Log::error('Could not load licenses from billing', ['org' => $org->id, 'error' => $e->getMessage()]);

            return [];
        }
    }

    private function invoices(Request $request)
    {
        return Invoice::query()->where('organisation_id', $request->user()->organisation_id)->latest('issued_at')->get();
    }

    /** Support tickets: sample data for the demo organisation until the helpdesk is connected (spec §12). */
    private function tickets(Request $request): array
    {
        return \App\Models\Organisation::find($request->user()->organisation_id)?->billing_org_id === \App\Services\Billing\LocalBillingClient::DEMO_ORG ? Demo::tickets() : [];
    }

    public function index(Request $request): View
    {
        return view('dashboard.index', ['licenses' => $this->licenses($request), 'invoices' => $this->invoices($request)->take(4), 'tickets' => $this->tickets($request)]);
    }

    public function licensesPage(Request $request): View
    {
        return view('dashboard.licenses', ['licenses' => $this->licenses($request), 'tiers' => Plan::active()->get()]);
    }

    public function downloads(Request $request): View
    {
        return view('dashboard.downloads', ['releases' => Demo::releases(), 'licenses' => $this->licenses($request)]);
    }

    public function billingPage(Request $request): View
    {
        return view('dashboard.billing', ['licenses' => $this->licenses($request), 'invoices' => $this->invoices($request), 'org' => \App\Models\Organisation::find($request->user()->organisation_id)]);
    }

    public function support(Request $request): View
    {
        return view('dashboard.support', ['tickets' => $this->tickets($request), 'licenses' => $this->licenses($request)]);
    }

    public function settings(Request $request): View
    {
        return view('dashboard.settings');
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'timezone' => ['nullable', 'timezone'],
        ]);
        $request->user()->update($data);
        if (! empty($data['timezone'])) {
            $request->session()->put('timezone', $data['timezone']);
        }

        return back()->with('toast', 'Profile saved');
    }

    public function updateBillingDetails(Request $request, OrganisationService $orgs): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email'],
            'country' => ['required', 'string', 'size:2'],
            'vat_id' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);
        $org = \App\Models\Organisation::find($request->user()->organisation_id);
        $org->update(['name' => $data['name'], 'email' => $data['email'], 'country' => strtoupper($data['country']), 'vat_id' => $data['vat_id'], 'address' => ['text' => $data['address']]]);
        $orgs->syncToBilling($org);

        return back()->with('toast', 'Billing details saved');
    }

    public function invoicePdf(Request $request, Invoice $invoice, InvoiceService $service)
    {
        abort_unless($invoice->organisation_id === $request->user()->organisation_id || $request->user()->is_admin, 404);

        return $service->pdf($invoice)->download($invoice->number.'.pdf');
    }

    public function invoiceView(Request $request, Invoice $invoice)
    {
        abort_unless($invoice->organisation_id === $request->user()->organisation_id || $request->user()->is_admin, 404);

        return view('invoices.pdf', ['invoice' => $invoice, 'screen' => true]);
    }

    /** Signed link (sent to billing.twsd.me) so the central admin can fetch the PDF. */
    public function invoiceSigned(string $invoice, InvoiceService $service)
    {
        $invoice = Invoice::query()->where('number', $invoice)->firstOrFail();

        return $service->pdf($invoice)->stream($invoice->number.'.pdf');
    }
}
