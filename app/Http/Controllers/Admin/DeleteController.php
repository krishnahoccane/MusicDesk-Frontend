<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\License;
use App\Models\Order;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Permanent deletion of orders, licenses and customers (team permission "Delete records", plus the
 * area's own permission). Each asks the admin to type the record's number or name. Recorded in the log.
 */
class DeleteController extends Controller
{
    /** An order (and, if asked, its invoices). A license it created is kept; delete that separately. */
    public function order(Request $request, Order $order): RedirectResponse
    {
        $this->confirm($request, $order->number);
        $withInvoices = $request->boolean('with_invoices');

        DB::transaction(function () use ($order, $withInvoices) {
            if ($withInvoices) {
                Invoice::query()->where('order_id', $order->id)->get()->each(fn (Invoice $i) => $this->deleteInvoice($i));
            }
            $order->delete();
        });
        $this->log($request, 'order', $order->number, ['invoices' => $withInvoices]);

        return redirect()->route('admin.orders')->with('toast', "Order {$order->number} deleted".($withInvoices ? ' with its invoices' : ''));
    }

    /**
     * A license with its add-on keys, domains and history. Installs using it fail their next check-in
     * (activation_not_found). Orders and invoices are kept, without the link.
     */
    public function license(Request $request, License $license): RedirectResponse
    {
        $this->confirm($request, $license->number);
        $numbers = $license->addonLicenses()->pluck('number')->prepend($license->number)->all();
        $parent = $license->parent;

        DB::transaction(fn () => $license->delete());   // add-on keys, activations, events cascade
        $this->log($request, 'license', implode(', ', $numbers));

        $to = $parent ? route('admin.licenses.show', $parent) : route('admin.licenses');

        return redirect()->to($to)->with('toast', 'Deleted '.implode(', ', $numbers));
    }

    /**
     * A customer: organisation, its user accounts, licenses (with add-ons, domains, history), orders,
     * invoices and support tickets. Refused when a team member belongs to it.
     */
    public function customer(Request $request, Organisation $organisation): RedirectResponse
    {
        $this->confirm($request, $organisation->name, 'name');
        if (User::query()->where('organisation_id', $organisation->id)->where('is_admin', true)->exists()) {
            throw ValidationException::withMessages(['confirm' => 'A team member belongs to this organisation. Remove them from the team first.']);
        }

        $counts = DB::transaction(function () use ($organisation) {
            $licenses = License::query()->where('organisation_id', $organisation->id);
            $counts = ['licenses' => (clone $licenses)->count(), 'orders' => $organisation->orders()->count(), 'users' => $organisation->users()->count()];
            (clone $licenses)->whereNotNull('parent_id')->delete();
            $licenses->delete();
            Invoice::query()->where('organisation_id', $organisation->id)->get()->each(fn (Invoice $i) => $this->deleteInvoice($i));
            User::query()->where('organisation_id', $organisation->id)->delete();
            $organisation->delete();   // orders, support tickets cascade

            return $counts;
        });
        $this->log($request, 'customer', $organisation->name, $counts);

        return redirect()->route('admin.customers')->with('toast', "{$organisation->name} deleted: {$counts['licenses']} licenses, {$counts['orders']} orders, {$counts['users']} user accounts");
    }

    // ------------------------------------------------------------ helpers

    private function confirm(Request $request, string $expected, string $what = 'number'): void
    {
        $request->validate(['confirm' => ['required', 'string']], ['confirm.required' => "Type the {$what} to confirm."]);
        if (trim((string) $request->input('confirm')) !== $expected) {
            throw ValidationException::withMessages(['confirm' => "Type {$expected} exactly to confirm."]);
        }
    }

    private function deleteInvoice(Invoice $invoice): void
    {
        $invoice->delete();   // PDFs are rendered on demand, nothing stored
    }

    private function log(Request $request, string $type, string $label, array $details = []): void
    {
        Log::notice("Admin deleted {$type} {$label}", ['by' => $request->user()->email, ...$details]);
    }
}
