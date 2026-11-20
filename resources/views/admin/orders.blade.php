@php
    $statusBadge = ['paid' => 'badge-mint', 'awaiting_payment' => 'badge-amber', 'pending' => 'badge-ink', 'failed' => 'badge-coral', 'cancelled' => 'badge-ink', 'refunded' => 'badge-sky'];
    $gatewayNames = ['razorpay' => 'Razorpay', 'stripe' => 'Stripe', 'paypal' => 'PayPal', 'bank_transfer' => 'Bank transfer'];
@endphp
<x-layouts.app area="admin" title="Orders" subtitle="Payments taken on this site. Licenses and organisations are administered in billing.twsd.me.">
    <x-slot:eyebrow>Admin · Overview</x-slot:eyebrow>

    <div class="card">
        <div class="flex flex-wrap gap-1 border-b hairline p-4 text-[13px]">
            @foreach (['' => 'All', 'paid' => 'Paid', 'awaiting_payment' => 'Awaiting transfer', 'pending' => 'Started', 'failed' => 'Failed', 'cancelled' => 'Cancelled', 'refunded' => 'Refunded'] as $k => $l)
                <a href="{{ route('admin.orders', array_filter(['status' => $k])) }}" class="rounded-lg px-3 py-1.5 font-medium transition {{ request('status', '') === $k ? 'bg-white/10 text-white' : 'text-ink-400 hover:text-white' }}">{{ $l }}</a>
            @endforeach
        </div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>Order</th><th>Organisation</th><th>Item</th><th>Total</th><th>Gateway</th><th>Status</th><th>Billing sync</th><th></th></tr></thead>
                <tbody>
                    @forelse ($orders as $o)
                        <tr>
                            <td><div class="font-mono text-xs text-white">{{ $o->number }}</div><div class="text-[11px] text-ink-500">{{ local_time($o->created_at)->format('M d, Y H:i') }}</div></td>
                            <td><div class="text-ink-100">{{ $o->organisation?->name }}</div><div class="font-mono text-[11px] text-ink-500">{{ $o->organisation?->billing_org_id ?? 'not in billing' }}</div></td>
                            <td class="text-ink-300">{{ $o->plan?->name }} · {{ ucfirst($o->billing_type) }}{{ $o->extra_domains ? ' +'.$o->extra_domains.' dom.' : '' }}</td>
                            <td><div class="font-mono text-white">{{ fmt_money($o->total, $o->currency) }}</div>@if ($o->currency !== 'USD')<div class="font-mono text-[11px] text-ink-500">≈ ${{ number_format($o->total / max($o->exchange_rate, 0.00000001), 2) }}</div>@endif</td>
                            <td class="text-ink-300">{{ $gatewayNames[$o->gateway] ?? $o->gateway }}</td>
                            <td><span class="badge badge-dot {{ $statusBadge[$o->status] ?? 'badge-ink' }}">{{ str_replace('_', ' ', $o->status) }}</span></td>
                            <td class="max-w-[220px] whitespace-normal">
                                @if ($o->billing_license_id)<span class="font-mono text-[11px] text-mint-400">{{ $o->meta['license_number'] ?? 'license issued' }}</span>
                                @elseif (in_array($o->status, ['paid', 'awaiting_payment'], true))<span class="text-[11px] text-coral-400">not in billing</span>
                                @else<span class="text-[11px] text-ink-500">—</span>@endif
                                @if (! empty($o->meta['billing_action']) || (! $o->billing_license_id && ! empty($o->meta['billing_error'])))
                                    <div class="mt-1 flex items-start gap-1.5 text-[11px] leading-snug text-amber-glow"><x-icon name="alert" class="mt-px size-3"/><span>{{ $o->meta['billing_error'] }}</span></div>
                                @endif
                            </td>
                            <td class="text-right">
                                <div x-data="{ m: false }" class="relative inline-block text-left">
                                    <button @click="m = !m" class="btn btn-ghost btn-sm !p-1.5" aria-label="Order actions"><x-icon name="more"/></button>
                                    <div x-show="m" x-cloak @click.outside="m = false" class="absolute right-0 z-20 mt-1 w-56 rounded-xl glass !bg-ink-900/95 p-1 shadow-xl">
                                        @if ($o->invoice)
                                            <a href="{{ route('dashboard.invoices.show', $o->invoice) }}" class="nav-link !py-1.5 text-xs"><x-icon name="receipt" class="size-3.5"/> Invoice {{ $o->invoice->number }}</a>
                                        @endif
                                        @if (in_array($o->status, ['awaiting_payment', 'pending', 'failed'], true))
                                            <form method="POST" action="{{ route('admin.orders.paid', $o) }}" onsubmit="const r = prompt('Payment reference (bank transaction id), optional:'); if (r === null) return false; this.reference.value = r;">
                                                @csrf<input type="hidden" name="reference"><button class="nav-link w-full !py-1.5 text-xs !text-mint-400"><x-icon name="check" class="size-3.5"/> Mark as paid</button>
                                            </form>
                                        @endif
                                        @if ($o->isPaid())
                                            <form method="POST" action="{{ route('admin.orders.refund', $o) }}"
                                                  onsubmit="if (!confirm('Record a refund for {{ $o->number }}? Issue the actual refund in the {{ $gatewayNames[$o->gateway] ?? 'gateway' }} dashboard first. Billing will revoke the license.')) return false; const r = prompt('Refund reference from the gateway (optional):'); if (r === null) return false; this.reference.value = r; this.reason.value = prompt('Reason (optional, shown on the license):', '30-day money-back guarantee') || '';">
                                                @csrf<input type="hidden" name="reference"><input type="hidden" name="reason">
                                                <button class="nav-link w-full !py-1.5 text-xs !text-coral-400"><x-icon name="refresh" class="size-3.5"/> Record refund</button>
                                            </form>
                                        @endif
                                        @if ((in_array($o->status, ['paid', 'awaiting_payment'], true) && ! $o->billing_license_id) || ! empty($o->meta['billing_action']))
                                            <form method="POST" action="{{ route('admin.orders.resync', $o) }}">@csrf<button class="nav-link w-full !py-1.5 text-xs"><x-icon name="upload" class="size-3.5"/> Retry in billing</button></form>
                                        @endif
                                        @if (! empty($o->meta['billing_action']))
                                            <form method="POST" action="{{ route('admin.orders.resolve', $o) }}">@csrf<button class="nav-link w-full !py-1.5 text-xs !text-mint-400"><x-icon name="check" class="size-3.5"/> Done in billing by hand</button></form>
                                        @endif
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="py-12 text-center text-ink-500">No orders{{ request('status') ? ' with this status' : '' }} yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($orders->hasPages())<div class="border-t hairline px-4 py-3 text-sm">{{ $orders->links() }}</div>@endif
    </div>
</x-layouts.app>
