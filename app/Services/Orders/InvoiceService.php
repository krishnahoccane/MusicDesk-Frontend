<?php

namespace App\Services\Orders;

use App\Models\Invoice;
use App\Models\Order;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;

/** Invoices are generated on musicdesk.tech only (not on billing.twsd.me). */
class InvoiceService
{
    /** Next number in the yearly sequence: PREFIX-2026-000042. */
    public function nextNumber(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');
        $prefix = strtoupper(trim((string) setting('invoice.prefix', 'MD'))) ?: 'MD';
        $like = "$prefix-$year-%";

        $last = Invoice::query()->where('number', 'like', $like)->lockForUpdate()->orderByDesc('number')->value('number');
        $seq = $last ? ((int) substr($last, strrpos($last, '-') + 1)) + 1 : 1;

        return sprintf('%s-%d-%06d', $prefix, $year, $seq);
    }

    /** Create (or return) the invoice for an order. */
    public function forOrder(Order $order): Invoice
    {
        return DB::transaction(function () use ($order) {
            if ($existing = $order->invoice()->first()) {
                return $existing;
            }

            $paid = $order->isPaid();

            return Invoice::create([
                'number' => $this->nextNumber(),
                'organisation_id' => $order->organisation_id,
                'order_id' => $order->id,
                'currency' => $order->currency,
                'exchange_rate' => $order->exchange_rate,
                'lines' => array_map(fn ($l) => array_intersect_key($l, array_flip(['description', 'quantity', 'unit', 'amount'])), $order->lines),
                'subtotal' => $order->subtotal,
                'discount' => $order->discount,
                'tax_rate' => $order->tax_rate,
                'tax' => $order->tax,
                'total' => $order->total,
                'status' => $paid ? 'paid' : 'open',
                'seller' => $this->seller(),
                'buyer' => $order->organisation->invoiceSnapshot(),
                'payment_method' => $order->gateway,
                'issued_at' => now(),
                'due_at' => $paid ? null : now()->addDays((int) setting('invoice.due_days', 14)),
                'paid_at' => $order->paid_at,
            ]);
        });
    }

    public function markPaid(Invoice $invoice): void
    {
        $invoice->update(['status' => 'paid', 'paid_at' => $invoice->paid_at ?? now(), 'due_at' => null]);
    }

    public function seller(): array
    {
        return [
            'name' => setting('invoice.company_name'),
            'address' => setting('invoice.company_address'),
            'tax_id' => setting('invoice.company_tax_id'),
            'email' => setting('invoice.company_email'),
            'country' => setting('invoice.seller_country'),
            'footer' => setting('invoice.footer'),
        ];
    }

    public function pdf(Invoice $invoice)
    {
        return Pdf::loadView('invoices.pdf', ['invoice' => $invoice->loadMissing('order')])->setPaper('a4');
    }
}
