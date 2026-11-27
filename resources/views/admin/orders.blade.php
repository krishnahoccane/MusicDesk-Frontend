@php
    $statusBadge = ['paid' => 'badge-mint', 'awaiting_payment' => 'badge-amber', 'pending' => 'badge-ink', 'failed' => 'badge-coral', 'cancelled' => 'badge-ink', 'refunded' => 'badge-sky'];
    $gatewayNames = ['razorpay' => 'Razorpay', 'stripe' => 'Stripe', 'paypal' => 'PayPal', 'bank_transfer' => 'Bank transfer', 'manual' => 'Manual'];
@endphp
<x-layouts.app area="admin" title="Orders" subtitle="Every purchase: new licenses, renewals, upgrades and extra domains. Paying an order applies it to the license.">
    <x-slot:eyebrow>Admin · Overview</x-slot:eyebrow>
    <x-slot:actions><button class="btn btn-primary btn-sm" @click="$dispatch('create-order')"><x-icon name="plus" class="size-3.5"/> Create order</button></x-slot:actions>
    @error('order')<div class="mb-4 flex items-center gap-3 rounded-xl border border-coral-500/30 bg-coral-500/10 p-3 text-sm text-coral-300"><x-icon name="alert" class="size-4"/> Order not created: {{ $message }}</div>@enderror

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
                                <div x-data="rowMenu" class="inline-block text-left" @scroll.window="m = false" @resize.window="m = false" @keydown.escape.window="m = false">
                                    <button @click.stop="toggle($event)" class="btn btn-ghost btn-sm !p-1.5" aria-label="Order actions" :aria-expanded="m"><x-icon name="more"/></button>
                                    <template x-teleport="body">
                                    <div x-show="m" x-cloak x-ref="menu" @click.outside="m = false" x-transition.opacity.duration.100ms class="fixed z-[70] w-56 rounded-xl glass !bg-ink-900/95 p-1 text-left shadow-2xl shadow-black/60" :style="`left:${x}px; top:${y}px`">
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
                                    </template>
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
    {{-- Create order (phone / email sale) --}}
    <x-modal event="create-order" title="Create order" subtitle="Record a sale made by phone or email. Paid orders are applied at once; unpaid ones wait like a bank transfer." width="max-w-2xl">
        <form method="POST" action="{{ route('admin.orders.store') }}" class="mt-5 space-y-4"
              x-data="{
                customers: @js($customers), org: @js((int) old('organisation_id')) || null, kind: @js(old('kind', 'new_license')),
                price: @js(old('price', 'list')), paid: @js((bool) old('paid', true)),
                get customer() { return this.customers.find(c => c.id === Number(this.org)) },
                get licenses() { return this.customer ? this.customer.licenses : [] },
              }"
              x-init="@if ($errors->any() && old('kind')) $nextTick(() => $dispatch('create-order')) @endif">
            @csrf
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2"><label class="label" for="co_org">Customer</label>
                    <select id="co_org" name="organisation_id" x-model="org" class="input" required>
                        <option value="">Choose a customer…</option>
                        <template x-for="c in customers" :key="c.id"><option :value="c.id" x-text="c.name + ' · ' + c.email" :selected="c.id === Number(org)"></option></template>
                    </select>
                    @error('organisation_id')<p class="mt-1 text-xs text-coral-400">{{ $message }}</p>@enderror
                    <p class="mt-1 text-[11px] text-ink-500">New customer? Add them with <a href="{{ route('admin.licenses.create') }}" class="text-volt-300 hover:text-white">Issue license</a> (it can record the sale too).</p></div>
                <div><label class="label" for="co_kind">Type</label>
                    <select id="co_kind" name="kind" x-model="kind" class="input">
                        @foreach (\App\Models\Order::KINDS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach
                    </select></div>
                <div x-show="kind !== 'new_license'"><label class="label" for="co_lic">License</label>
                    <select id="co_lic" name="license_id" class="input" :disabled="kind === 'new_license'">
                        <template x-for="l in licenses" :key="l.id"><option :value="l.id" x-text="l.label" :selected="l.id === @js((int) old('license_id'))"></option></template>
                    </select>
                    <p class="mt-1 text-[11px] text-ink-500" x-show="org && ! licenses.length">This customer has no licenses yet.</p>
                    @error('license_id')<p class="mt-1 text-xs text-coral-400">{{ $message }}</p>@enderror</div>
                <div x-show="kind === 'new_license' || kind === 'change_plan'"><label class="label" for="co_plan" x-text="kind === 'change_plan' ? 'Upgrade to' : 'Plan'"></label>
                    <select id="co_plan" name="plan_id" class="input" :disabled="! (kind === 'new_license' || kind === 'change_plan')">
                        @foreach ($plans as $p)<option value="{{ $p->id }}" @selected((int) old('plan_id') === $p->id)>{{ $p->name }}</option>@endforeach
                    </select></div>
                <div x-show="kind === 'addon'"><label class="label" for="co_addon">Add-on</label>
                    <select id="co_addon" name="addon_id" class="input" :disabled="kind !== 'addon'">
                        @foreach ($licenseAddons as $a)<option value="{{ $a->id }}" @selected((int) old('addon_id') === $a->id)>{{ $a->name }}</option>@endforeach
                    </select></div>
                <div x-show="kind === 'new_license' || kind === 'addon'"><label class="label" for="co_bill">Billing</label>
                    <select id="co_bill" name="billing" class="input"><option value="annual">Annual</option><option value="lifetime" @selected(old('billing') === 'lifetime')>Lifetime</option></select></div>
                <div x-show="kind === 'new_license' || kind === 'extra_domains'"><label class="label" for="co_qty" x-text="kind === 'extra_domains' ? 'Extra domains' : 'Extra domains (on top of the plan)'"></label>
                    <input id="co_qty" type="number" name="quantity" min="0" max="50" value="{{ old('quantity', 0) }}" class="input font-mono"></div>
            </div>

            <div class="grid gap-4 border-t hairline pt-4 sm:grid-cols-2">
                <div class="sm:col-span-2 flex gap-2">
                    @foreach (['list' => 'List price (with the customer’s tax)', 'custom' => 'Custom total'] as $pk => $pl)
                        <label class="flex-1 cursor-pointer rounded-xl border px-3 py-2 text-center text-sm transition" :class="price === '{{ $pk }}' ? 'border-volt-500/60 bg-volt-500/10 text-white' : 'border-white/[0.08] text-ink-400'">
                            <input type="radio" name="price" value="{{ $pk }}" x-model="price" class="sr-only">{{ $pl }}</label>
                    @endforeach
                </div>
                <div x-show="price === 'custom'"><label class="label" for="co_amount">Total</label><input id="co_amount" type="number" step="0.01" min="0" name="amount" value="{{ old('amount') }}" class="input font-mono" :required="price === 'custom'">
                    @error('amount')<p class="mt-1 text-xs text-coral-400">{{ $message }}</p>@enderror</div>
                <div><label class="label" for="co_cur">Currency</label>
                    <select id="co_cur" name="currency" class="input font-mono">
                        @foreach ($currencies as $c)<option value="{{ $c }}" :selected="customer ? customer.currency === '{{ $c }}' : '{{ $c }}' === 'USD'">{{ $c }}</option>@endforeach
                    </select></div>
                <div><label class="label" for="co_gw">Payment</label>
                    <select id="co_gw" name="gateway" class="input"><option value="bank_transfer">Bank transfer</option><option value="manual" @selected(old('gateway') === 'manual')>Other / manual</option></select></div>
                <label class="flex items-end gap-2 pb-2.5 text-sm text-ink-200"><input type="hidden" name="paid" value="0"><input type="checkbox" name="paid" value="1" x-model="paid" class="size-4 accent-volt-500"> Paid already</label>
                <div x-show="paid"><label class="label" for="co_ref">Payment reference <span class="text-ink-500">(optional)</span></label><input id="co_ref" name="reference" value="{{ old('reference') }}" class="input font-mono text-sm"></div>
                <div class="sm:col-span-2"><label class="label" for="co_note">Internal note <span class="text-ink-500">(optional)</span></label><input id="co_note" name="note" value="{{ old('note') }}" maxlength="500" class="input text-sm"></div>
            </div>
            <p class="text-[11px] text-ink-500" x-show="! paid">Unpaid: an invoice is issued as unpaid; a new license is created as pending (usable 14 days) and other changes apply when you mark the order paid.</p>
            <div class="flex justify-end gap-2 border-t hairline pt-4">
                <button type="button" class="btn btn-ghost" @click="open = false">Cancel</button>
                <button class="btn btn-primary" :disabled="! org"><x-icon name="plus" class="size-3.5"/> Create order</button>
            </div>
        </form>
    </x-modal>
</x-layouts.app>
