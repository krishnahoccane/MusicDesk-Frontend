@php $p = $plan; $k = $p?->id ?? 'new'; $tl = $p?->trialLimits() ?? (new \App\Models\Plan)->trialLimits(); @endphp
<div class="grid gap-4 p-5 sm:grid-cols-2 sm:p-6 lg:grid-cols-4">
    <div><label class="label" for="name_{{ $k }}">Name</label><input id="name_{{ $k }}" name="name" value="{{ $p?->name }}" class="input" required></div>
    <div><label class="label" for="code_{{ $k }}">Code (URL)</label><input id="code_{{ $k }}" name="code" value="{{ $p?->code }}" class="input font-mono" pattern="[a-z0-9_-]+" required></div>
    <div><label class="label" for="annual_{{ $k }}">Annual price (USD)</label><input id="annual_{{ $k }}" type="number" step="0.01" min="0" name="price_annual" value="{{ $p?->price_annual }}" class="input font-mono" required></div>
    <div><label class="label" for="life_{{ $k }}">Lifetime price (USD)</label><input id="life_{{ $k }}" type="number" step="0.01" min="0" name="price_lifetime" value="{{ $p?->price_lifetime }}" class="input font-mono" placeholder="Blank = contact sales"></div>
    <div><label class="label" for="dom_{{ $k }}">Domains included</label><input id="dom_{{ $k }}" type="number" min="1" name="domains" value="{{ $p?->domains ?? 1 }}" class="input font-mono" required></div>
    <div><label class="label" for="extra_{{ $k }}">Extra domain (USD / yr)</label><input id="extra_{{ $k }}" type="number" step="0.01" min="0" name="extra_domain_price" value="{{ $p?->extra_domain_price ?? 0 }}" class="input font-mono" required></div>
    <div><label class="label" for="sort_{{ $k }}">Sort order</label><input id="sort_{{ $k }}" type="number" name="sort" value="{{ $p?->sort ?? 0 }}" class="input font-mono"></div>
    <div class="flex items-end gap-4 pb-2.5">
        <label class="flex items-center gap-2 text-sm text-ink-200"><input type="checkbox" name="active" value="1" @checked($p?->active ?? true) class="size-4 accent-volt-500"> On sale</label>
        <label class="flex items-center gap-2 text-sm text-ink-200"><input type="checkbox" name="featured" value="1" @checked($p?->featured) class="size-4 accent-volt-500"> Featured</label>
    </div>
    <div class="sm:col-span-2 lg:col-span-4"><label class="label" for="blurb_{{ $k }}">Short description</label><input id="blurb_{{ $k }}" name="blurb" value="{{ $p?->blurb }}" class="input" maxlength="200"></div>
    <div class="sm:col-span-2 lg:col-span-4"><label class="label" for="feat_{{ $k }}">Features (one per line)</label><textarea id="feat_{{ $k }}" name="features" rows="5" class="input text-sm">{{ implode("\n", $p?->features ?? []) }}</textarea></div>

    {{-- Free trial --}}
    <div class="sm:col-span-2 lg:col-span-4 rounded-2xl border hairline bg-white/[0.02] p-4" x-data="{ trial: {{ $p?->trial_enabled ? 'true' : 'false' }} }">
        <label class="flex items-center gap-2 text-sm font-medium text-white"><input type="checkbox" name="trial_enabled" value="1" x-model="trial" class="size-4 accent-volt-500"> Offer a free trial of this plan</label>
        <p class="mt-1 text-[11px] text-ink-500">Customers start it from the pricing page without paying (one trial per customer account, and a domain can run only one trial). When it ends, {{ setting('branding.product_name', 'LabelDesk') }} goes read-only for 10 days like an expired license, then the trial license is suspended. Buying the plan turns the trial into a paid license with the same key and domains.</p>
        <div x-show="trial" x-collapse x-cloak class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div><label class="label" for="tdays_{{ $k }}">Trial length (days)</label><input id="tdays_{{ $k }}" type="number" min="1" max="365" name="trial_days" value="{{ $p?->trial_days ?? 14 }}" class="input font-mono" :required="trial"></div>
            <div><label class="label" for="tdom_{{ $k }}">Production domains</label><input id="tdom_{{ $k }}" type="number" min="1" max="100" name="trial_domains" value="{{ $tl['domains'] }}" class="input font-mono"></div>
            @foreach (\App\Models\Plan::TRIAL_LIMITS as $lk => $ll)
                <div><label class="label" for="t{{ $lk }}_{{ $k }}">Max {{ strtolower($ll) }}</label><input id="t{{ $lk }}_{{ $k }}" type="number" min="1" name="trial_{{ $lk }}" value="{{ $tl[$lk] }}" placeholder="No limit" class="input font-mono"></div>
            @endforeach
            <label class="flex items-end gap-2 pb-2.5 text-sm text-ink-200"><input type="checkbox" name="trial_addons" value="1" @checked($tl['addons']) class="size-4 accent-volt-500"> Include the plan’s add-ons</label>
            <div class="sm:col-span-2 lg:col-span-4"><label class="label" for="tnote_{{ $k }}">Other limitations <span class="text-ink-500">(shown to customers)</span></label><input id="tnote_{{ $k }}" name="trial_note" value="{{ $tl['note'] }}" maxlength="300" placeholder="e.g. No store deliveries during the trial" class="input text-sm"></div>
        </div>
    </div>
</div>
