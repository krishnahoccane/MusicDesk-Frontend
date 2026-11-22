@php $a = $addon; $sel = collect(old('plans', $a?->plans->pluck('id')->all() ?? []))->map(fn ($v) => (int) $v); @endphp
<div class="grid gap-4 p-5 sm:grid-cols-2 sm:p-6">
    <div><label class="label" for="la-name-{{ $a?->id ?? 'new' }}">Add-on name</label>
        <input id="la-name-{{ $a?->id ?? 'new' }}" name="name" value="{{ old('name', $a?->name) }}" class="input" required maxlength="80" placeholder="DDEX delivery"></div>
    <div><label class="label" for="la-code-{{ $a?->id ?? 'new' }}">Code</label>
        <input id="la-code-{{ $a?->id ?? 'new' }}" name="code" value="{{ old('code', $a?->code) }}" class="input font-mono" required maxlength="40" pattern="[A-Za-z0-9_\-]+" placeholder="ddex">
        <p class="mt-1 text-[11px] text-ink-500">Sent to LabelDesk in the license token (<span class="font-mono">addon</span>) to unlock the module.</p></div>
    <div><label class="label" for="la-annual-{{ $a?->id ?? 'new' }}">Annual price (USD)</label>
        <input id="la-annual-{{ $a?->id ?? 'new' }}" type="number" step="0.01" min="0" name="price_annual" value="{{ old('price_annual', $a?->price_annual) }}" class="input font-mono" required></div>
    <div><label class="label" for="la-life-{{ $a?->id ?? 'new' }}">Lifetime price (USD) <span class="text-ink-500">(optional)</span></label>
        <input id="la-life-{{ $a?->id ?? 'new' }}" type="number" step="0.01" min="0" name="price_lifetime" value="{{ old('price_lifetime', $a?->price_lifetime) }}" class="input font-mono" placeholder="Not sold as lifetime"></div>
    <div class="sm:col-span-2"><label class="label" for="la-desc-{{ $a?->id ?? 'new' }}">Description</label>
        <input id="la-desc-{{ $a?->id ?? 'new' }}" name="description" value="{{ old('description', $a?->description) }}" class="input" maxlength="300" placeholder="Deliver releases to DSPs as DDEX ERN 4.3"></div>
    <fieldset class="sm:col-span-2">
        <legend class="label">Included free with</legend>
        <div class="flex flex-wrap gap-2">
            @foreach ($plans as $p)
                <label class="flex cursor-pointer items-center gap-2 rounded-xl border hairline px-3 py-2 text-sm text-ink-200 transition hover:border-white/10 has-[:checked]:border-volt-500/50 has-[:checked]:bg-volt-500/10 has-[:checked]:text-white">
                    <input type="checkbox" name="plans[]" value="{{ $p->id }}" @checked($sel->contains($p->id)) class="size-4 accent-volt-500"> {{ $p->name }}
                </label>
            @endforeach
        </div>
        <p class="mt-1.5 text-[11px] text-ink-500">Licenses on these plans get a separate add-on key at no charge when they’re bought or upgraded to the plan. Other plans can buy it.</p>
    </fieldset>
    <div class="flex flex-wrap gap-x-6 gap-y-2 sm:col-span-2">
        <label class="flex items-center gap-2 text-sm text-ink-300"><input type="checkbox" name="active" value="1" @checked(old('active', $a?->active ?? true)) class="size-4 accent-volt-500"> On sale</label>
        <label class="flex items-center gap-2 text-sm text-ink-300"><input type="checkbox" name="issue_existing" value="1" class="size-4 accent-volt-500"> Also issue keys now to existing licenses on the included plans</label>
    </div>
</div>
