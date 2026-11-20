@php $p = $plan; $k = $p?->id ?? 'new'; @endphp
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
</div>
