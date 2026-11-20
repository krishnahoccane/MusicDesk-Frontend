<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Organisation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Customers (organisations): everything about one client in one place. */
class CustomerAdminController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q'));

        $customers = Organisation::query()
            ->withCount(['licenses', 'users', 'licenses as active_licenses_count' => fn ($l) => $l->whereIn('status', ['active', 'pending'])])
            ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s->where('name', 'like', "%$q%")->orWhere('email', 'like', "%$q%")
                ->orWhereHas('users', fn ($u) => $u->where('email', 'like', "%$q%")->orWhere('name', 'like', "%$q%"))))
            ->latest('id')->paginate(25)->withQueryString();

        // Revenue per customer in USD (invoice totals ÷ the exchange rate at the time).
        $revenue = Invoice::query()->whereIn('organisation_id', $customers->pluck('id'))->where('status', 'paid')->get()
            ->groupBy('organisation_id')->map(fn ($rows) => $rows->sum(fn ($i) => $i->total / max($i->exchange_rate, 0.00000001)));

        return view('admin.customers.index', ['customers' => $customers, 'revenue' => $revenue]);
    }

    public function show(Organisation $organisation): View
    {
        $organisation->load(['users', 'licenses.plan', 'licenses.activations', 'orders' => fn ($q) => $q->latest()->with('invoice', 'license'), 'invoices' => fn ($q) => $q->latest('issued_at')]);

        return view('admin.customers.show', [
            'org' => $organisation,
            'revenueUsd' => $organisation->invoices->where('status', 'paid')->sum(fn ($i) => $i->total / max($i->exchange_rate, 0.00000001)),
        ]);
    }

    public function update(Request $request, Organisation $organisation): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:190'],
            'country' => ['nullable', 'string', 'size:2'],
            'vat_id' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $organisation->update(['country' => isset($data['country']) ? strtoupper($data['country']) : null] + $data);

        return back()->with('toast', 'Customer saved');
    }
}
