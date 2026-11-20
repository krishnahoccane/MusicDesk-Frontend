{{-- Add / move domain --}}
<x-modal event="open-domain" title="Add a domain" subtitle="Pre-approve a domain so LabelDesk activates there as soon as it checks in.">
    <form class="mt-6 space-y-4" x-data="{ env: 'production', domain: '', busy: false }"
          @submit.prevent="busy = true; try {
              const r = data.move
                  ? await portalApi(data.base + '/domains/' + encodeURIComponent(data.act) + '/move', 'POST', { new_domain: domain })
                  : await portalApi(data.base + '/domains', 'POST', { domain, environment: env });
              open = false; toast(r.message); setTimeout(() => location.reload(), 700);
          } catch (e) {} finally { busy = false }">
        <div x-show="data.move" class="rounded-xl border border-amber-glow/25 bg-amber-glow/[0.06] p-3 text-sm text-amber-glow">
            Moving from <b x-text="data.move"></b>. That install switches to read-only once the new domain checks in. Up to 3 moves per 30 days.
        </div>
        <div><label class="label" for="new_domain">Domain</label>
            <div class="flex"><span class="grid place-items-center rounded-l-xl border border-r-0 border-white/[0.08] bg-white/[0.03] px-3 text-sm text-ink-500">https://</span><input id="new_domain" x-model="domain" class="input !rounded-l-none" placeholder="portal.yourbrand.com" required></div></div>
        <div x-show="!data.move">
            <label class="label">Environment</label>
            <div class="grid grid-cols-2 gap-2">
                <button type="button" @click="env = 'production'" :class="env === 'production' ? 'border-volt-500/60 bg-volt-500/10 text-white' : 'border-white/[0.08] text-ink-300'" class="rounded-xl border px-3 py-2.5 text-left text-sm transition"><span class="block font-medium">Production</span><span class="text-xs text-ink-500">Uses a domain slot</span></button>
                <button type="button" @click="env = 'staging'" :class="env === 'staging' ? 'border-volt-500/60 bg-volt-500/10 text-white' : 'border-white/[0.08] text-ink-300'" class="rounded-xl border px-3 py-2.5 text-left text-sm transition"><span class="block font-medium">Staging / dev</span><span class="text-xs text-ink-500">Free · noindex enforced</span></button>
            </div>
        </div>
        <div class="flex justify-end gap-2 pt-2"><button type="button" class="btn btn-ghost" @click="open = false">Cancel</button><button class="btn btn-primary" :disabled="busy" x-text="busy ? 'Working…' : (data.move ? 'Move domain' : 'Add domain')"></button></div>
    </form>
</x-modal>

{{-- Buy extra domain slots --}}
<x-modal event="open-slots" title="Buy extra domains" subtitle="Extra domains are prorated to your license’s renewal date.">
    <div class="mt-6" x-data="{ n: 1, price: {{ money(129)['amount'] }}, sym: @js(trim(app(\App\Services\Currency\CurrencyService::class)->symbol(app(\App\Services\Currency\CurrencyService::class)->current()))) }">
        <div class="flex items-center justify-between rounded-2xl border hairline bg-white/[0.02] p-4">
            <div><div class="text-sm text-white">Extra production domains for <span class="font-mono" x-text="data.id"></span></div><div class="text-xs text-ink-500"><span x-text="sym + price.toLocaleString()"></span> per domain per year</div></div>
            <div class="flex items-center gap-1">
                <button @click="n = Math.max(1, n - 1)" class="grid size-8 place-items-center rounded-lg bg-white/[0.06] hover:bg-white/10">−</button>
                <span class="w-8 text-center font-mono text-white" x-text="n"></span>
                <button @click="n++" class="grid size-8 place-items-center rounded-lg bg-white/[0.06] hover:bg-white/10">+</button>
            </div>
        </div>
        <div class="mt-4 flex items-center justify-between text-sm"><span class="text-ink-400">Prorated today (114 days left)</span><span class="font-mono text-white" x-text="sym + (n * price * 114 / 365).toFixed(2)"></span></div>
        <div class="mt-6 flex justify-end gap-2"><button class="btn btn-ghost" @click="open = false">Cancel</button><button class="btn btn-primary" @click="open = false; toast(n + ' domain slot(s) added')">Pay & add</button></div>
    </div>
</x-modal>

{{-- Renew --}}
<x-modal event="open-renew" title="Renew">
    <div class="mt-5 space-y-4">
        <div class="rounded-2xl border hairline bg-white/[0.02] p-4">
            <div class="flex items-center justify-between gap-4">
                <div><div class="font-medium text-white" x-text="data.label"></div><div class="font-mono text-xs text-ink-500"><span x-text="data.id"></span> · LabelDesk <span x-text="data.tier"></span> · 12 months</div></div>
                <div class="text-xl font-semibold text-white" x-text="data.price"></div>
            </div>
        </div>
        <label class="flex items-center justify-between rounded-xl border hairline p-3 text-sm">
            <span><span class="block text-ink-100">Turn on auto-renew</span><span class="text-xs text-ink-500">Keep updates and support without gaps. Cancel any time.</span></span>
            <button type="button" class="switch" x-data="{ on: true }" :aria-checked="on.toString()" @click="on = !on" role="switch"><span></span></button>
        </label>
        <div class="flex items-center gap-3 rounded-xl border hairline p-3 text-sm"><x-icon name="card" class="text-ink-400"/><span class="flex-1 text-ink-200">Visa ending 4242</span><a href="{{ route('dashboard.billing') }}" class="text-xs text-volt-300">Change</a></div>
        <div class="flex justify-end gap-2 pt-2"><button class="btn btn-ghost" @click="open = false">Cancel</button><button class="btn btn-primary" @click="open = false; toast(data.id + ' renewed for 12 months')">Pay & renew</button></div>
    </div>
</x-modal>

{{-- Switch to lifetime --}}
<x-modal event="open-lifetime" title="Switch to a lifetime license" subtitle="We credit the unused part of your annual license.">
    <div class="mt-6 space-y-3 text-sm" x-data="{ lifetime: {{ json_encode($tiers->filter(fn ($t) => $t->price_lifetime)->mapWithKeys(fn ($t) => [$t->name => money($t->price_lifetime)['display']])) }} }">
        <div class="flex justify-between"><span class="text-ink-400">Lifetime license (<span x-text="data.tier"></span>)</span><span class="font-mono text-white" x-text="lifetime[data.tier] ?? 'Contact sales'"></span></div>
        <div class="flex justify-between"><span class="text-mint-400">Credit for unused annual time</span><span class="text-mint-400">Calculated at payment</span></div>
        <p class="text-xs text-ink-500">Your key and domains stay the same. Includes 12 months of updates from today.</p>
        <div class="flex justify-end gap-2 pt-2"><button class="btn btn-ghost" @click="open = false">Cancel</button><button class="btn btn-primary" @click="open = false; toast('Switched to a lifetime license')">Switch to lifetime</button></div>
    </div>
</x-modal>

{{-- Upgrade --}}
<x-modal event="open-upgrade" title="Upgrade tier" subtitle="You only pay the difference for the time left on your license. Your key stays the same." width="max-w-2xl">
    <div class="mt-6 grid gap-3 sm:grid-cols-3" x-data="{ pick: @js($tiers->firstWhere('featured', true)?->code ?? $tiers->first()?->code) }">
        @foreach ($tiers as $t)
            <button @click="pick = '{{ $t->code }}'" :disabled="data.tier === '{{ $t->name }}'"
                    :class="pick === '{{ $t->code }}' && data.tier !== '{{ $t->name }}' ? 'border-volt-500/60 bg-volt-500/10 ring-4 ring-volt-500/10' : 'border-white/[0.08] hover:border-white/20'"
                    class="rounded-2xl border p-4 text-left transition disabled:cursor-not-allowed disabled:opacity-40">
                <div class="flex items-center justify-between"><span class="font-medium text-white">{{ $t['name'] }}</span><span x-show="data.tier === '{{ $t['name'] }}'" class="badge badge-ink !text-[10px]">Current</span></div>
                <div class="mt-1 text-xs text-ink-400">{{ $t['domains'] }} production {{ Str::plural('domain', $t['domains']) }}</div>
                <div class="mt-3 text-lg font-semibold text-white">{{ money($t->price_annual)['display'] }}<span class="text-xs font-normal text-ink-500">/yr</span></div>
            </button>
        @endforeach
        <div class="flex justify-end gap-2 pt-3 sm:col-span-3"><button class="btn btn-ghost" @click="open = false">Cancel</button><button class="btn btn-primary" @click="open = false; toast('License upgraded')">Upgrade license</button></div>
    </div>
</x-modal>

{{-- Offline activation --}}
<x-modal event="open-offline" title="Offline activation" subtitle="For servers that can’t reach our license server. Upload the request file from LabelDesk and get back a signed activation file.">
    <div class="mt-6 space-y-4" x-data="{ file: null }">
        <div><label class="label">License</label>
            <select class="input">@foreach ($licenses as $l)<option>{{ $l['id'] }} · {{ $l['tier'] }} {{ $l['type'] }}</option>@endforeach</select></div>
        <label class="grid cursor-pointer place-items-center rounded-2xl border border-dashed border-white/15 px-6 py-10 text-center transition hover:border-volt-500/50 hover:bg-volt-500/[0.04]">
            <x-icon name="upload" class="size-7 text-volt-300"/>
            <span class="mt-3 text-sm font-medium text-white" x-text="file ?? 'Drop activation-request.ldreq'"></span>
            <span class="mt-1 text-xs text-ink-500">In LabelDesk admin: Settings → License → Activate offline</span>
            <input type="file" class="sr-only" accept=".ldreq" @change="file = $event.target.files[0]?.name">
        </label>
        <div class="flex justify-end gap-2"><button class="btn btn-ghost" @click="open = false">Cancel</button><button class="btn btn-primary" :disabled="!file" @click="open = false; toast('activation.ldlic downloaded')"><x-icon name="download" class="size-4"/> Generate activation file</button></div>
    </div>
</x-modal>

{{-- Buy --}}
<x-modal event="open-buy" title="Buy a LabelDesk license" width="max-w-2xl">
    <div class="mt-6 grid gap-3 sm:grid-cols-3">
        @foreach ($tiers as $t)
            <a href="{{ route('checkout', $t->code) }}" class="group rounded-2xl border border-white/[0.08] p-4 transition hover:border-volt-500/50 hover:bg-volt-500/[0.05]">
                <div class="font-medium text-white">{{ $t['name'] }}</div>
                <div class="mt-1 text-xs text-ink-400">{{ $t['domains'] }} production {{ Str::plural('domain', $t['domains']) }}</div>
                <div class="mt-3 text-xl font-semibold text-white">{{ money($t->price_annual)['display'] }}<span class="text-xs font-normal text-ink-500">/yr</span></div>
                <div class="mt-3 flex items-center gap-1 text-xs text-volt-300">Continue <x-icon name="arrow-right" class="size-3 transition group-hover:translate-x-0.5"/></div>
            </a>
        @endforeach
    </div>
</x-modal>
