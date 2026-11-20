@php $sample = $plans->first(); @endphp
<x-layouts.app area="admin" title="Currencies" subtitle="Rates update daily at 00:05 UTC. Converted prices are rounded down to end in 9s (charm pricing).">
    <x-slot:eyebrow>Admin · Payments</x-slot:eyebrow>
    <x-slot:actions>
        <form method="POST" action="{{ route('admin.currencies.refresh') }}">@csrf<button class="btn btn-outline btn-sm"><x-icon name="refresh" class="size-3.5"/> Update rates now</button></form>
        <button class="btn btn-primary btn-sm" @click="$dispatch('open-currency')"><x-icon name="plus" class="size-3.5"/> Add currency</button>
    </x-slot:actions>

    <div class="mb-4 rounded-2xl border hairline bg-white/[0.02] p-4 text-sm text-ink-300">
        <span class="font-medium text-white">How prices are calculated:</span> USD list price × rate, then rounded <em>down</em> to the price ending in the most 9s that stays within
        a {{ setting('general.charm_max_discount') }}% discount (<a href="{{ route('admin.settings', 'general') }}" class="text-volt-300">change</a>).
        Example: $499 × 92.64 = ₹46,227.36 → <span class="font-mono text-white">₹45,999</span>. A manual rate overrides the daily rate.
    </div>

    <form method="POST" action="{{ route('admin.currencies.update') }}" class="card">
        @csrf
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>Currency</th><th>Symbol</th><th>Daily rate (1 USD =)</th><th>Manual rate</th><th>{{ $sample?->name }} annual</th><th>Enabled</th><th>Auto-update</th><th>Charm</th></tr></thead>
                <tbody>
                    @foreach ($currencies as $c)
                        @php $p = $sample ? money($sample->price_annual, $c->code) : null; @endphp
                        <tr>
                            <td><div class="font-mono font-medium text-white">{{ $c->code }}</div><div class="text-xs text-ink-500">{{ $c->name }}</div></td>
                            <td><input name="currencies[{{ $c->code }}][symbol]" value="{{ $c->symbol }}" class="input w-20 !py-1.5 font-mono text-sm"></td>
                            <td>
                                <div class="font-mono text-ink-100">{{ $c->code === 'USD' ? '1' : rtrim(rtrim(number_format($c->rate, 6), '0'), '.') }}</div>
                                <div class="text-[11px] text-ink-500">{{ $c->code === 'USD' ? 'Base currency' : ($c->rate_updated_at ? 'Updated '.$c->rate_updated_at->diffForHumans() : 'Never') }}</div>
                            </td>
                            <td><input type="number" step="any" min="0" name="currencies[{{ $c->code }}][manual_rate]" value="{{ $c->manual_rate }}" class="input w-28 !py-1.5 font-mono text-sm" placeholder="—" @disabled($c->code === 'USD')></td>
                            <td>
                                @if ($p && $c->enabled)
                                    <div class="font-mono text-white">{{ $p['display'] }}</div>
                                    @if ($p['discounted'])<div class="font-mono text-[11px] text-ink-500 line-through">{{ $p['exact_display'] }}</div>@endif
                                @else
                                    <span class="text-ink-600">—</span>
                                @endif
                            </td>
                            <td><input type="checkbox" name="currencies[{{ $c->code }}][enabled]" value="1" @checked($c->enabled) @disabled($c->code === 'USD') class="size-4 accent-volt-500"></td>
                            <td><input type="checkbox" name="currencies[{{ $c->code }}][auto_update]" value="1" @checked($c->auto_update) @disabled($c->code === 'USD') class="size-4 accent-volt-500"></td>
                            <td><input type="checkbox" name="currencies[{{ $c->code }}][charm_pricing]" value="1" @checked($c->charm_pricing) @disabled($c->code === 'USD') class="size-4 accent-volt-500"></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="flex items-center justify-between border-t hairline px-5 py-4 sm:px-6">
            <p class="text-xs text-ink-500">Scheduler: run <span class="font-mono">php artisan schedule:work</span> (dev) or a cron for <span class="font-mono">schedule:run</span> every minute (production).</p>
            <button class="btn btn-primary btn-sm">Save currencies</button>
        </div>
    </form>

    <x-modal event="open-currency" title="Add a currency">
        <form method="POST" action="{{ route('admin.currencies.store') }}" class="mt-6 grid grid-cols-2 gap-4">
            @csrf
            <div><label class="label" for="cc">Code</label><input id="cc" name="code" class="input font-mono uppercase" maxlength="3" placeholder="CHF" required></div>
            <div><label class="label" for="cs">Symbol</label><input id="cs" name="symbol" class="input font-mono" placeholder="CHF " required></div>
            <div class="col-span-2"><label class="label" for="cn">Name</label><input id="cn" name="name" class="input" placeholder="Swiss Franc" required></div>
            <div><label class="label" for="cr">Starting rate (1 USD =)</label><input id="cr" type="number" step="any" min="0" name="rate" class="input font-mono" required></div>
            <div><label class="label" for="cd">Decimals</label><input id="cd" type="number" min="0" max="3" name="decimals" value="2" class="input font-mono" required></div>
            <p class="col-span-2 text-xs text-ink-500">The daily update replaces the starting rate, as long as the provider publishes this currency.</p>
            <div class="col-span-2 flex justify-end gap-2"><button type="button" class="btn btn-ghost" @click="open = false">Cancel</button><button class="btn btn-primary">Add currency</button></div>
        </form>
    </x-modal>
</x-layouts.app>
