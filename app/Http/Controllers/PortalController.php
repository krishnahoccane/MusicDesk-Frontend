<?php

namespace App\Http\Controllers;

use App\Licensing\LicensePresenter;
use App\Models\Invoice;
use App\Models\License;
use App\Models\Organisation;
use App\Models\Plan;
use App\Models\Release;
use App\Models\SupportTicket;
use App\Services\Orders\InvoiceService;
use App\Support\Demo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class PortalController extends Controller
{
    private function org(Request $request): ?Organisation
    {
        return Organisation::find($request->user()->organisation_id);
    }

    private function licenses(Request $request): array
    {
        return LicensePresenter::forOrganisation($this->org($request));
    }

    private function invoices(Request $request)
    {
        return Invoice::query()->where('organisation_id', $request->user()->organisation_id)->latest('issued_at')->get();
    }

    /** Latest support tickets, unresolved first. */
    private function tickets(Request $request)
    {
        return SupportTicket::query()->where('organisation_id', $request->user()->organisation_id)
            ->orderByRaw("case status when 'resolved' then 1 else 0 end")->latest('last_reply_at')->limit(3)->get();
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
        $releases = Release::published()->get();

        return view('dashboard.downloads', [
            'releases' => $releases->map(fn (Release $r, $i) => [
                'id' => $r->id,
                'version' => $r->version,
                'date' => local_time($r->released_at)->format('M d, Y'),
                'tag' => $i === 0 ? 'Latest' : ($r->channel === 'beta' ? 'Beta' : null),
                'notes' => array_map(fn ($n) => [$n['kind'] ?? 'new', $n['text'] ?? ''], $r->notes ?? []),
                'size' => $r->size_bytes,
                'sha256' => $r->sha256,
                'requirements' => $r->requirements ?? [],
                'has_file' => $r->hasFile(),
                'released_at' => $r->released_at,
            ])->all(),
            'licenses' => $this->licenses($request),
        ]);
    }

    /** Download a release with a license that is entitled to it (updates period covers the release date). */
    public function download(Request $request, Release $release)
    {
        abort_unless($release->published, 404);
        $license = License::query()->where('uuid', (string) $request->query('license'))->where('organisation_id', $request->user()->organisation_id)->first();
        abort_unless($license, 404);

        if (! $license->mayDownload($release)) {
            return back()->with(['toast' => "{$license->number} can’t download {$release->version}: its updates period ended before this release.", 'toast_tone' => 'danger']);
        }
        if (! $release->hasFile()) {
            return back()->with(['toast' => "The {$release->version} package isn’t uploaded yet. Please contact support.", 'toast_tone' => 'danger']);
        }

        return Storage::disk('local')->download($release->file_path, $release->fileName());
    }

    public function billingPage(Request $request): View
    {
        return view('dashboard.billing', ['licenses' => $this->licenses($request), 'invoices' => $this->invoices($request), 'org' => $this->org($request)]);
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

    public function updateBillingDetails(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email'],
            'country' => ['required', 'string', 'size:2'],
            'vat_id' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);
        $this->org($request)->update(['name' => $data['name'], 'email' => $data['email'], 'country' => strtoupper($data['country']), 'vat_id' => $data['vat_id'], 'address' => ['text' => $data['address']]]);

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
}
