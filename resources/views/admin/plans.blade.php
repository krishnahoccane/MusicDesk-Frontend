<x-layouts.app area="admin" title="Pricing plans" subtitle="License plans, licensed add-ons, services and coupons. Prices are set in USD and converted for each visitor.">
    <x-slot:eyebrow>Admin · Storefront</x-slot:eyebrow>
    <x-slot:actions>
        <button class="btn btn-outline btn-sm" @click="$dispatch('open-new-addon')"><x-icon name="layers" class="size-3.5"/> New add-on</button>
        <button class="btn btn-primary btn-sm" @click="$dispatch('open-new-plan')"><x-icon name="plus" class="size-3.5"/> New plan</button>
    </x-slot:actions>

    <div class="space-y-4">
        @foreach ($plans as $plan)
            <form method="POST" action="{{ route('admin.plans.update', $plan) }}" class="card" x-data="{ open: false }">
                @csrf @method('PUT')
                <div class="flex flex-wrap items-center gap-4 p-5 sm:p-6">
                    <span class="grid size-11 place-items-center rounded-xl {{ $plan->featured ? 'bg-gradient-to-br from-volt-500 to-flare-500 text-white' : 'bg-white/[0.04] text-volt-300' }}"><x-icon name="key" class="size-5"/></span>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2"><span class="font-semibold text-white">{{ $plan->name }}</span>
                            <span class="badge {{ $plan->active ? 'badge-mint' : 'badge-ink' }}">{{ $plan->active ? 'On sale' : 'Hidden' }}</span>
                            @if ($plan->featured)<span class="badge badge-volt">Featured</span>@endif
                            @if ($plan->offersTrial())<span class="badge badge-amber" title="{{ $plan->trialSummary() }}">{{ $plan->trial_days }}-day free trial</span>@endif</div>
                        <div class="text-xs text-ink-500"><span class="font-mono">{{ $plan->code }}</span> · {{ $plan->domains }} domains · ${{ number_format($plan->price_annual) }}/yr{{ $plan->price_lifetime ? ' · $'.number_format($plan->price_lifetime).' lifetime' : '' }}</div>
                    </div>
                    <div class="hidden gap-1.5 lg:flex">
                        @foreach ($currencies->where('code', '!=', 'USD')->take(4) as $c)
                            <span class="rounded-lg bg-white/[0.04] px-2 py-1 font-mono text-[11px] text-ink-300" title="{{ $c->name }}">{{ money($plan->price_annual, $c->code)['display'] }}</span>
                        @endforeach
                    </div>
                    <button type="button" class="btn btn-outline btn-sm" @click="open = !open"><span x-text="open ? 'Close' : 'Edit'"></span></button>
                </div>
                <div x-show="open" x-collapse x-cloak class="border-t hairline">
                    @include('admin.partials.plan-fields', ['plan' => $plan])
                    <div class="flex items-center justify-between border-t hairline px-5 py-4 sm:px-6">
                        <button type="submit" form="del-{{ $plan->id }}" class="btn btn-danger btn-sm"><x-icon name="trash" class="size-3.5"/> Remove</button>
                        <button class="btn btn-primary btn-sm">Save {{ $plan->name }}</button>
                    </div>
                </div>
            </form>
            <form id="del-{{ $plan->id }}" method="POST" action="{{ route('admin.plans.destroy', $plan) }}" onsubmit="return confirm('Remove {{ $plan->name }}? Plans with orders are hidden instead of deleted.')">@csrf @method('DELETE')</form>
        @endforeach
    </div>

    {{-- Licensed add-ons --}}
    <div class="mt-10 mb-3 flex items-end justify-between gap-4" id="license-addons">
        <div>
            <h2 class="font-semibold text-white">Licensed add-ons</h2>
            <p class="mt-0.5 text-sm text-ink-400">Optional modules with their own license key. Sold annual or lifetime for a main license, or included free with a plan.</p>
        </div>
        <button class="btn btn-outline btn-sm shrink-0" @click="$dispatch('open-new-addon')"><x-icon name="plus" class="size-3.5"/> New add-on</button>
    </div>
    <div class="space-y-3">
        @forelse ($licenseAddons as $la)
            <form method="POST" action="{{ route('admin.plans.license-addons.update', $la) }}" class="card" x-data="{ open: false }">
                @csrf @method('PUT')
                <div class="flex flex-wrap items-center gap-4 p-5">
                    <span class="grid size-11 place-items-center rounded-xl bg-white/[0.04] text-volt-300 ring-1 ring-white/[0.06]"><x-icon :name="$la->icon" class="size-5"/></span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2"><span class="font-semibold text-white">{{ $la->name }}</span>
                            <span class="badge {{ $la->active ? 'badge-mint' : 'badge-ink' }}">{{ $la->active ? 'On sale' : 'Hidden' }}</span>
                            @foreach ($la->plans as $p)<span class="badge badge-volt">Free with {{ $p->name }}</span>@endforeach</div>
                        <div class="text-xs text-ink-500"><span class="font-mono">{{ $la->code }}</span> · ${{ number_format($la->price_annual) }}/yr{{ $la->price_lifetime ? ' · $'.number_format($la->price_lifetime).' lifetime' : '' }} · {{ $la->keys_count }} {{ Str::plural('key', $la->keys_count) }} issued</div>
                    </div>
                    <button type="button" class="btn btn-outline btn-sm" @click="open = !open"><span x-text="open ? 'Close' : 'Edit'"></span></button>
                </div>
                <div x-show="open" x-collapse x-cloak class="border-t hairline">
                    @include('admin.partials.license-addon-fields', ['addon' => $la])
                    <div class="flex items-center justify-between border-t hairline px-5 py-4 sm:px-6">
                        <button type="submit" form="del-addon-{{ $la->id }}" class="btn btn-danger btn-sm"><x-icon name="trash" class="size-3.5"/> Remove</button>
                        <button class="btn btn-primary btn-sm">Save {{ $la->name }}</button>
                    </div>
                </div>
            </form>
            <form id="del-addon-{{ $la->id }}" method="POST" action="{{ route('admin.plans.license-addons.destroy', $la) }}" onsubmit="return confirm(@js('Remove '.$la->name.'? Add-ons with keys or releases are hidden instead of deleted.'))">@csrf @method('DELETE')</form>
        @empty
            <div class="card flex flex-col items-center px-6 py-10 text-center">
                <span class="grid size-11 place-items-center rounded-xl bg-white/[0.04] text-ink-400"><x-icon name="layers" class="size-5"/></span>
                <div class="mt-3 text-sm font-medium text-white">No licensed add-ons yet</div>
                <p class="mt-1 max-w-md text-xs text-ink-500">Create one to sell a LabelDesk module separately, or to include it with higher plans.</p>
                <button class="btn btn-primary btn-sm mt-4" @click="$dispatch('open-new-addon')"><x-icon name="plus" class="size-3.5"/> New add-on</button>
            </div>
        @endforelse
    </div>

    <div class="mt-10 grid gap-4 xl:grid-cols-[1fr_380px]">
        {{-- Services --}}
        <form method="POST" action="{{ route('admin.plans.addons') }}" class="card">
            @csrf
            <div class="p-5 sm:p-6"><h2 class="font-semibold text-white">Services</h2><p class="text-sm text-ink-400">One-off and recurring services (no license key). Shown on the pricing page; tick “Checkout” to offer them in checkout.</p></div>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th>Name & description</th><th>Price (USD)</th><th>Billing</th><th>From</th><th>Checkout</th><th>Active</th></tr></thead>
                    <tbody>
                        @foreach ($addons as $a)
                            <tr>
                                <td class="min-w-[260px] whitespace-normal">
                                    <input name="addons[{{ $a->id }}][name]" value="{{ $a->name }}" class="input !py-1.5 text-sm">
                                    <input name="addons[{{ $a->id }}][description]" value="{{ $a->description }}" class="input mt-1.5 !py-1.5 text-xs">
                                </td>
                                <td><input type="number" step="0.01" min="0" name="addons[{{ $a->id }}][price]" value="{{ $a->price }}" class="input w-28 !py-1.5 font-mono text-sm"></td>
                                <td><select name="addons[{{ $a->id }}][interval]" class="input w-32 !py-1.5 text-sm"><option value="">One-time</option><option value="month" @selected($a->interval === 'month')>Monthly</option><option value="year" @selected($a->interval === 'year')>Yearly</option></select></td>
                                <td><input type="checkbox" name="addons[{{ $a->id }}][price_from]" value="1" @checked($a->price_from) class="size-4 accent-volt-500"></td>
                                <td><input type="checkbox" name="addons[{{ $a->id }}][checkout]" value="1" @checked($a->checkout) class="size-4 accent-volt-500"></td>
                                <td><input type="checkbox" name="addons[{{ $a->id }}][active]" value="1" @checked($a->active) class="size-4 accent-volt-500"></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="flex justify-end border-t hairline px-5 py-4 sm:px-6"><button class="btn btn-primary btn-sm">Save services</button></div>
        </form>

        {{-- Coupons & notes --}}
        <form method="POST" action="{{ route('admin.plans.coupons') }}" class="card card-pad space-y-4">
            @csrf
            <div><h2 class="font-semibold text-white">Coupons & notes</h2></div>
            <div><label class="label" for="coupons">Coupons</label>
                <textarea id="coupons" name="coupons" rows="5" class="input font-mono text-sm">{{ setting('pricing.coupons') }}</textarea>
                <p class="mt-1.5 text-xs text-ink-500">One per line: <span class="font-mono">CODE=PERCENT</span>. Applies to license and domain lines, not services.</p></div>
            <div><label class="label" for="annual_note">Annual license note</label><textarea id="annual_note" name="annual_note" rows="2" class="input text-sm">{{ setting('pricing.annual_note') }}</textarea></div>
            <div><label class="label" for="lifetime_note">Lifetime license note</label><textarea id="lifetime_note" name="lifetime_note" rows="2" class="input text-sm">{{ setting('pricing.lifetime_note') }}</textarea></div>
            <button class="btn btn-primary btn-sm w-full">Save</button>
        </form>
    </div>

    <x-modal event="open-new-addon" title="New licensed add-on" subtitle="Each purchase gets its own license key." width="max-w-2xl">
        <form method="POST" action="{{ route('admin.plans.license-addons.store') }}" class="-mx-6 mt-2">
            @csrf
            @include('admin.partials.license-addon-fields', ['addon' => null])
            <div class="flex justify-end gap-2 border-t hairline px-6 pt-4"><button type="button" class="btn btn-ghost" @click="open = false">Cancel</button><button class="btn btn-primary">Create add-on</button></div>
        </form>
    </x-modal>
    @if ($errors->hasAny(['code', 'price_annual', 'price_lifetime', 'plans']) && ! old('domains'))<div x-data x-init="$nextTick(() => $dispatch('open-new-addon'))"></div>@endif

    <x-modal event="open-new-plan" title="New plan" width="max-w-2xl">
        <form method="POST" action="{{ route('admin.plans.store') }}" class="-mx-6 mt-4">
            @csrf
            @include('admin.partials.plan-fields', ['plan' => null])
            <div class="flex justify-end gap-2 border-t hairline px-6 pt-4"><button type="button" class="btn btn-ghost" @click="open = false">Cancel</button><button class="btn btn-primary">Create plan</button></div>
        </form>
    </x-modal>
</x-layouts.app>
