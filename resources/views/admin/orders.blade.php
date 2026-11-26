@php
    $statusBadge = ['paid' => 'badge-mint', 'awaiting_payment' => 'badge-amber', 'pending' => 'badge-ink', 'failed' => 'badge-coral', 'cancelled' => 'badge-ink', 'refunded' => 'badge-sky'];
    $gatewayNames = ['razorpay' => 'Razorpay', 'stripe' => 'Stripe', 'paypal' => 'PayPal', 'bank_transfer' => 'Bank transfer', 'manual' => 'Manual'];
@endphp
<x-layouts.app area="admin" title="Orders" subtitle="Every purchase: new licenses, renewals, upgrades and extra domains. Paying an order applies it to the license.">
    <x-slot:eyebrow>Admin · Overview</x-slot:eyebrow>

    <div class="card">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b hairline p-4 text-[13px]">
            <div class="flex flex-wrap gap-1">
                @foreach (['' => 'All', 'paid' => 'Paid', 'awaiting_payment' => 'Awaiting transfer', 'pending' => 'Started', 'failed' => 'Failed', 'cancelled' => 'Cancelled', 'refunded' => 'Refunded'] as $k => $l)
                    <a href="{{ route('admin.orders', array_filter(['status' => $k, 'kind' => request('kind')])) }}" class="rounded-lg px-3 py-1.5 font-medium transition {{ request('status', '') === $k ? 'bg-white/10 text-white' : 'text-ink-400 hover:text-white' }}">{{ $l }}</a>
                @endforeach
            </div>
            <form method="GET" class="flex items-center gap-2">
                <input type="hidden" name="status" value="{{ request('status') }}">
                <select name="kind" class="input !w-auto !py-1.5 text-[13px]" onchange="this.form.submit()" aria-label="Order type">
                    <option value="">All types</option>
                    @foreach (\App\Models\Order::KINDS as $k => $l)<option value="{{ $k }}" @selected(request('kind') === $k)>{{ $l }}</option>@endforeach
                </select>
            </form>
        </div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>Order</th><th>Customer</th><th>Item</th><th>Total</th><th>Gateway</th><th>Status</th><th>License</th><th></th></tr></thead>
                <tbody>
                    @forelse ($orders as $o)
                        <tr>
                            <td><div class="font-mono text-xs text-white">{{ $o->number }}</div><div class="text-[11px] text-ink-500">{{ local_time($o->created_at)->format('M d, Y H:i') }}</div></td>
                            <td>
                                <div class="text-ink-100">{{ $o->organisation?->name }}</div>
                                @if ($o->organisation)<a href="{{ route('admin.customers.show', $o->organisation) }}" class="text-[11px] text-volt-300 hover:text-white">View customer</a>@endif
                            </td>
                            <td class="text-ink-300">
                                <div class="text-ink-100">{{ $o->kindLabel() }}</div>
                                <div class="text-xs">{{ $o->plan?->name }} · {{ ucfirst($o->billing_type) }}{{ $o->extra_domains ? ' · '.$o->extra_domains.' extra domain(s)' : '' }}</div>
                            </td>
                            <td><div class="font-mono text-white">{{ fmt_money($o->total, $o->currency) }}</div>@if ($o->currency !== 'USD')<div class="font-mono text-[11px] text-ink-500">≈ ${{ number_format($o->total / max($o->exchange_rate, 0.00000001), 2) }}</div>@endif</td>
                            <td class="text-ink-300">{{ $gatewayNames[$o->gateway] ?? $o->gateway }}</td>
                            <td>
                                <span class="badge badge-dot {{ $statusBadge[$o->status] ?? 'badge-ink' }}">{{ str_replace('_', ' ', $o->status) }}</span>
                                @if ($o->refund_reason)<div class="mt-1 max-w-[180px] truncate text-[11px] text-ink-500" title="{{ $o->refund_reason }}">{{ $o->refund_reason }}</div>@endif
                            </td>
                            <td>
                                @if ($o->license)
                                    <a href="{{ route('admin.licenses.show', $o->license) }}" class="font-mono text-[11px] text-volt-300 hover:text-white">{{ $o->license->number }}</a>
                                @else
                                    <span class="text-[11px] text-ink-500">—</span>
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
                                                  onsubmit="if (!confirm('Record a refund for {{ $o->number }}? Issue the actual refund in the {{ $gatewayNames[$o->gateway] ?? 'gateway' }} dashboard first. A refunded new license is revoked.')) return false; const r = prompt('Refund reference from the gateway (optional):'); if (r === null) return false; this.reference.value = r; this.reason.value = prompt('Reason (optional, shown on the license):', '30-day money-back guarantee') || '';">
                                                @csrf<input type="hidden" name="reference"><input type="hidden" name="reason">
                                                <button class="nav-link w-full !py-1.5 text-xs !text-coral-400"><x-icon name="refresh" class="size-3.5"/> Record refund</button>
                                            </form>
                                        @endif
                                        @if (auth()->user()->canAdmin('delete'))
                                            <div class="my-1 border-t hairline"></div>
                                            <button class="nav-link w-full !py-1.5 text-xs !text-coral-400" @click="m = false; $dispatch('delete-order', { action: @js(route('admin.orders.destroy', $o)), expected: @js($o->number) })"><x-icon name="trash" class="size-3.5"/> Delete order</button>
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
    @if (auth()->user()->canAdmin('delete'))
        @include('admin.partials.delete-modal', ['event' => 'delete-order', 'title' => 'Delete order', 'invoices' => true,
            'warning' => 'The order is removed from Orders and from the customer’s billing history. A license it created is kept; delete that from its license page if needed.'])
    @endif
</x-layouts.app>
