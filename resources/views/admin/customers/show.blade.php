@php
    use App\Support\Demo;
    $statusBadge = ['paid' => 'badge-mint', 'awaiting_payment' => 'badge-amber', 'pending' => 'badge-ink', 'failed' => 'badge-coral', 'cancelled' => 'badge-ink', 'refunded' => 'badge-sky', 'open' => 'badge-amber'];
@endphp
<x-layouts.app area="admin" :title="$org->name" :subtitle="$org->email.($org->country ? ' · '.$org->country : '').' · customer since '.local_time($org->created_at)->format('M Y')">
    <x-slot:eyebrow><a href="{{ route('admin.customers') }}" class="hover:text-white">Admin · Customers</a></x-slot:eyebrow>
    <x-slot:actions>
        <a href="{{ route('admin.licenses.create', ['organisation' => $org->id]) }}" class="btn btn-primary btn-sm"><x-icon name="key" class="size-3.5"/> Issue license</a>
        @if (auth()->user()->canAdmin('delete'))
            <button class="btn btn-danger btn-sm" @click="$dispatch('delete-customer')"><x-icon name="trash" class="size-3.5"/> Delete customer</button>
        @endif
    </x-slot:actions>
    @if (auth()->user()->canAdmin('delete'))
        @include('admin.partials.delete-modal', ['event' => 'delete-customer', 'title' => 'Delete '.$org->name, 'action' => route('admin.customers.destroy', $org), 'expected' => $org->name, 'what' => 'name',
            'warning' => 'Deletes the organisation with its '.$org->users()->count().' user account(s), '.$org->licenses->count().' license(s) (installs lock at their next check-in), '.$org->orders()->count().' order(s), their invoices and support tickets.'])
    @endif

    <div class="grid gap-4 sm:grid-cols-4">
        <x-stat label="Licenses" value="{{ $org->licenses->count() }}" icon="key" color="#ff3fd2"/>
        <x-stat label="Live domains" value="{{ $org->licenses->flatMap->activations->where('status', '!=', 'deactivated')->count() }}" icon="globe" color="#7c5cff"/>
        <x-stat label="Orders" value="{{ $org->orders->count() }}" icon="receipt" color="#5cc8ff"/>
        <x-stat label="Revenue (≈ USD)" value="{{ '$'.number_format($revenueUsd, 0) }}" icon="wallet" color="#22d9a0"/>
    </div>

    <div class="mt-4 grid gap-4 xl:grid-cols-[minmax(0,1fr)_360px]">
        <div class="space-y-4">
            <div class="card">
                <div class="p-5 sm:p-6"><h2 class="font-semibold text-white">Licenses</h2></div>
                <div class="overflow-x-auto"><table class="table">
                    <thead><tr><th>License</th><th>Plan</th><th>Domains</th><th>Renews / updates until</th><th>Status</th></tr></thead>
                    <tbody>
                        @forelse ($org->licenses as $l)
                            <tr>
                                <td><a href="{{ route('admin.licenses.show', $l) }}" class="font-mono text-xs text-volt-300 hover:text-white">{{ $l->number }}</a></td>
                                <td class="text-ink-300">{{ $l->plan->name }} · {{ ucfirst($l->billing_type) }}</td>
                                <td class="font-mono text-ink-200">{{ $l->activations->where('status', '!=', 'deactivated')->where('environment', 'production')->count() }} / {{ $l->domainLimit() }}</td>
                                <td class="text-ink-300">{{ local_time($l->expires_at ?? $l->support_until)?->format('M d, Y') }}</td>
                                <td><span class="badge badge-dot {{ Demo::statusBadge($l->displayStatus()) }}">{{ $l->displayStatus() }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-8 text-center text-ink-500">No licenses.</td></tr>
                        @endforelse
                    </tbody>
                </table></div>
            </div>

            <div class="card">
                <div class="p-5 sm:p-6"><h2 class="font-semibold text-white">Orders & invoices</h2></div>
                <div class="overflow-x-auto"><table class="table">
                    <thead><tr><th>Order</th><th>Type</th><th>Total</th><th>Status</th><th>Invoice</th></tr></thead>
                    <tbody>
                        @forelse ($org->orders as $o)
                            <tr>
                                <td><div class="font-mono text-xs text-white">{{ $o->number }}</div><div class="text-[11px] text-ink-500">{{ local_time($o->created_at)->format('M d, Y') }}</div></td>
                                <td class="text-ink-300">{{ $o->kindLabel() }}{{ $o->license ? ' · '.$o->license->number : '' }}</td>
                                <td class="font-mono text-white">{{ fmt_money($o->total, $o->currency) }}</td>
                                <td><span class="badge badge-dot {{ $statusBadge[$o->status] ?? 'badge-ink' }}">{{ str_replace('_', ' ', $o->status) }}</span></td>
                                <td>@if ($o->invoice)<a href="{{ route('dashboard.invoices.show', $o->invoice) }}" class="font-mono text-xs text-volt-300">{{ $o->invoice->number }}</a>@else — @endif</td>
                            </tr>
                        @empty
                            @if ($org->invoices->whereNull('order_id')->isEmpty())<tr><td colspan="5" class="py-8 text-center text-ink-500">No orders.</td></tr>@endif
                        @endforelse
                        @foreach ($org->invoices->whereNull('order_id') as $i)
                            <tr class="opacity-80">
                                <td class="text-[11px] text-ink-500">(no order)</td>
                                <td class="text-ink-300">{{ $i->description() }}</td>
                                <td class="font-mono text-white">{{ fmt_money($i->total, $i->currency) }}</td>
                                <td><span class="badge badge-dot {{ $statusBadge[$i->status] ?? 'badge-ink' }}">{{ $i->status }}</span></td>
                                <td><a href="{{ route('dashboard.invoices.show', $i) }}" class="font-mono text-xs text-volt-300">{{ $i->number }}</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
            </div>
        </div>

        <aside class="space-y-4">
            <form method="POST" action="{{ route('admin.customers.update', $org) }}" class="card card-pad space-y-3">
                @csrf @method('PUT')
                <h2 class="font-semibold text-white">Details</h2>
                <div><label class="label" for="c_name">Organisation</label><input id="c_name" name="name" value="{{ $org->name }}" class="input" required></div>
                <div><label class="label" for="c_email">Billing email</label><input id="c_email" name="email" type="email" value="{{ $org->email }}" class="input" required></div>
                <div class="grid grid-cols-2 gap-3">
                    <div><label class="label" for="c_country">Country</label><input id="c_country" name="country" maxlength="2" value="{{ $org->country }}" class="input uppercase"></div>
                    <div><label class="label" for="c_vat">VAT / GST ID</label><input id="c_vat" name="vat_id" value="{{ $org->vat_id }}" class="input"></div>
                </div>
                <div><label class="label" for="c_notes">Internal notes</label><textarea id="c_notes" name="notes" rows="3" class="input text-sm">{{ $org->notes }}</textarea></div>
                <button class="btn btn-primary btn-sm w-full">Save</button>
            </form>

            <div class="card card-pad">
                <h2 class="font-semibold text-white">Team</h2>
                <ul class="mt-3 space-y-2 text-sm">
                    @forelse ($org->users as $u)
                        <li class="flex items-center justify-between gap-3"><span><span class="block text-ink-100">{{ $u->name }}</span><span class="text-xs text-ink-500">{{ $u->email }}</span></span><span class="badge badge-ink capitalize">{{ $u->role }}</span></li>
                    @empty
                        <li class="text-ink-500">No portal accounts yet.</li>
                    @endforelse
                </ul>
            </div>
        </aside>
    </div>
</x-layouts.app>
