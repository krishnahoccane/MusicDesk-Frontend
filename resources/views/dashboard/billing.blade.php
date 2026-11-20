@php
    $renewals = collect($licenses)->filter(fn ($l) => $l['renews'] && ($l['type'] ?? '') === 'Annual')->sortBy('days_left');
    $priceOf = fn ($l) => isset($l['price_currency']) ? fmt_money($l['price'], $l['price_currency']) : money($l['price'])['display'];
    // Sum the prices as displayed (each already converted and charm-rounded), so the total matches the rows.
    $renewTotal = $renewals->reject(fn ($l) => isset($l['price_currency']))->sum(fn ($l) => money($l['price'])['amount']);
    $lastOrder = $org?->orders()->where('status', 'paid')->latest('paid_at')->first();
    $gatewayNames = ['razorpay' => 'Razorpay', 'stripe' => 'Stripe', 'paypal' => 'PayPal', 'bank_transfer' => 'Bank transfer'];
    $countries = ['US' => 'United States', 'IN' => 'India', 'GB' => 'United Kingdom', 'DE' => 'Germany', 'FR' => 'France', 'IT' => 'Italy', 'ES' => 'Spain', 'NL' => 'Netherlands', 'IE' => 'Ireland', 'CA' => 'Canada', 'AU' => 'Australia', 'NZ' => 'New Zealand', 'SG' => 'Singapore', 'AE' => 'United Arab Emirates', 'JP' => 'Japan', 'BR' => 'Brazil', 'MX' => 'Mexico', 'ZA' => 'South Africa', 'NG' => 'Nigeria'];
    $statusBadge = ['paid' => 'badge-mint', 'open' => 'badge-amber', 'refunded' => 'badge-sky', 'void' => 'badge-ink'];
@endphp
<x-layouts.app title="Billing" subtitle="Renewals, billing details and invoices. Invoices are issued by {{ setting('invoice.company_name') }}.">
    <div class="grid gap-4 lg:grid-cols-3">
        {{-- Renewals --}}
        <div class="card lg:col-span-2">
            <div class="flex items-center justify-between p-5 sm:p-6">
                <div><h2 class="font-semibold text-white">Upcoming renewals</h2><p class="text-sm text-ink-400">Annual licenses renew on these dates</p></div>
                @if ($renewTotal > 0)
                    <div class="text-right"><div class="text-xs text-ink-500">Next 12 months</div><div class="text-xl font-semibold text-white">{{ fmt_money($renewTotal, $localeCurrency) }}</div></div>
                @endif
            </div>
            <div class="divide-y divide-white/[0.04] border-t hairline">
                @forelse ($renewals as $l)
                    <div class="flex flex-col gap-4 px-5 py-4 sm:flex-row sm:items-center sm:px-6" x-data="{ on: {{ $l['auto_renew'] ? 'true' : 'false' }} }">
                        <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-white/[0.04] text-flare-400"><x-icon name="key" class="size-4"/></span>
                        <div class="flex-1">
                            <div class="text-sm font-medium text-white">{{ setting('branding.product_name') }} {{ $l['tier'] }} · Annual</div>
                            <div class="text-xs text-ink-500"><span class="font-mono">{{ $l['id'] }}</span> · renews {{ $l['renews'] }}
                                @if ($l['status'] === 'expiring')<span class="text-amber-glow"> · {{ $l['days_left'] }} days left</span>@endif</div>
                        </div>
                        <div class="font-mono text-sm text-white">{{ $priceOf($l) }}</div>
                        <label class="flex items-center gap-2 text-xs text-ink-400">
                            <span x-text="on ? 'Reminders on' : 'Reminders off'"></span>
                            <button type="button" class="switch" :aria-checked="on.toString()" @click="on = !on; portalApi(@js(route('dashboard.licenses.auto-renew', $l['uid'])), 'POST', { auto_renew: on }).then(r => toast(r.message)).catch(() => on = !on)" role="switch" title="Email reminders 30, 14 and 3 days before renewal"><span></span></button>
                        </label>
                        @if (in_array($l['status'], ['expiring', 'expired'], true))
                            <a href="{{ route('licenses.buy', [$l['uid'], 'renewal']) }}" class="btn btn-sm bg-amber-glow text-ink-950 hover:brightness-110">Renew now</a>
                        @endif
                    </div>
                @empty
                    <p class="px-6 py-8 text-sm text-ink-500">No upcoming renewals. Lifetime licenses don’t renew.</p>
                @endforelse
            </div>
        </div>

        {{-- Payment --}}
        <div class="card card-pad">
            <h2 class="font-semibold text-white">How you pay</h2>
            <p class="mt-1 text-sm text-ink-400">You choose a payment method each time you buy or renew. Card details stay with the payment provider, never with us. Renewals aren’t charged automatically: we email you 30, 14 and 3 days before.</p>
            <div class="mt-4 rounded-xl border hairline p-4 text-sm">
                <div class="text-xs text-ink-500">Last payment</div>
                @if ($lastOrder)
                    <div class="mt-1 text-white">{{ fmt_money($lastOrder->total, $lastOrder->currency) }} via {{ $gatewayNames[$lastOrder->gateway] ?? $lastOrder->gateway }}</div>
                    <div class="text-xs text-ink-500">{{ local_time($lastOrder->paid_at)->format('M d, Y · H:i') }}</div>
                @else
                    <div class="mt-1 text-ink-300">No payments on this site yet</div>
                @endif
            </div>
            <div class="mt-3 rounded-xl border hairline p-4 text-sm">
                <div class="text-xs text-ink-500">Billing currency</div>
                <div class="mt-1 flex items-center justify-between"><span class="text-white">{{ $localeCurrency }}</span><x-currency-switcher/></div>
            </div>
        </div>
    </div>

    <div class="mt-4 grid gap-4 lg:grid-cols-3">
        {{-- Billing details --}}
        <form method="POST" action="{{ route('dashboard.billing.details') }}" class="card card-pad space-y-4">
            @csrf
            <div><h2 class="font-semibold text-white">Billing details</h2><p class="text-sm text-ink-400">Printed on invoices. Synced to your organisation record.</p></div>
            <div><label class="label" for="b_name">Organisation</label><input id="b_name" name="name" class="input" value="{{ old('name', $org?->name) }}" required></div>
            <div><label class="label" for="b_email">Billing email</label><input id="b_email" type="email" name="email" class="input" value="{{ old('email', $org?->email) }}" required></div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="label" for="b_country">Country</label>
                    <select id="b_country" name="country" class="input">@foreach ($countries as $cc => $cn)<option value="{{ $cc }}" @selected(($org?->country ?? 'US') === $cc)>{{ $cn }}</option>@endforeach</select></div>
                <div><label class="label" for="b_vat">VAT / GST ID</label><input id="b_vat" name="vat_id" class="input" value="{{ old('vat_id', $org?->vat_id) }}"></div>
            </div>
            <div><label class="label" for="b_addr">Address</label><textarea id="b_addr" name="address" class="input min-h-20">{{ old('address', $org?->address['text'] ?? '') }}</textarea></div>
            <button class="btn btn-primary btn-sm w-full">Save details</button>
        </form>

        {{-- Invoices --}}
        <div class="card lg:col-span-2">
            <div class="flex items-center justify-between p-5 sm:p-6">
                <h2 class="font-semibold text-white">Invoices</h2>
                <span class="text-xs text-ink-500">Dates in {{ $localeTimezone }}</span>
            </div>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th>Invoice</th><th>Date</th><th>Description</th><th>Amount</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($invoices as $i)
                            <tr>
                                <td><a href="{{ route('dashboard.invoices.show', $i) }}" class="font-mono text-xs text-white hover:text-volt-300">{{ $i->number }}</a></td>
                                <td class="text-ink-300">{{ local_time($i->issued_at)->format('M d, Y') }}</td>
                                <td class="max-w-[260px] truncate text-ink-200">{{ $i->description() }}</td>
                                <td class="font-mono text-white">{{ fmt_money($i->total, $i->currency) }}</td>
                                <td><span class="badge badge-dot {{ $statusBadge[$i->status] ?? 'badge-ink' }}">{{ $i->status === 'open' ? 'Due '.local_time($i->due_at)?->format('M d') : ucfirst($i->status) }}</span></td>
                                <td class="text-right"><a href="{{ route('dashboard.invoices.pdf', $i) }}" class="btn btn-ghost btn-sm"><x-icon name="download" class="size-3.5"/> PDF</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-10 text-center text-ink-500">No invoices yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-layouts.app>
