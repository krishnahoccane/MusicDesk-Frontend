{{-- LabelDesk license tiers (from /admin/plans) priced in the visitor's currency. Expects $plans. --}}
@php
    $cs = app(\App\Services\Currency\CurrencyService::class);
    $code = $cs->current();
    $cur = $cs->find($code);
    $showExact = setting('general.show_converted_original');
    $prices = $plans->mapWithKeys(fn ($p) => [$p->code => [
        'annual' => money($p->price_annual),
        'lifetime' => $p->price_lifetime ? money($p->price_lifetime) : null,
        'domain_annual' => money($p->usdExtraDomainPrice('annual')),
        'domain_lifetime' => money($p->usdExtraDomainPrice('lifetime')),
    ]]);
    $js = $prices->map(fn ($p) => [
        'annual' => $p['annual']['amount'], 'lifetime' => $p['lifetime']['amount'] ?? null,
        'domain_annual' => $p['domain_annual']['amount'], 'domain_lifetime' => $p['domain_lifetime']['amount'],
    ]);
@endphp
<div x-data="{
        type: 'annual',
        extra: {{ json_encode($plans->mapWithKeys(fn ($p) => [$p->code => 0])) }},
        prices: {{ json_encode($js) }},
        fmt(v) { return @js(trim($cur?->symbol ?? '$')) + v.toLocaleString(undefined, { minimumFractionDigits: v % 1 ? {{ $cur?->decimals ?? 2 }} : 0, maximumFractionDigits: {{ $cur?->decimals ?? 2 }} }) },
        total(code) { const p = this.prices[code]; const base = this.type === 'annual' ? p.annual : p.lifetime; return base === null ? null : base + this.extra[code] * (this.type === 'annual' ? p.domain_annual : p.domain_lifetime) },
     }">
    <div class="flex justify-center">
        <div class="inline-flex rounded-2xl glass p-1 text-sm">
            <button @click="type = 'annual'" :class="type === 'annual' ? 'bg-white text-ink-950 shadow' : 'text-ink-300 hover:text-white'" class="rounded-xl px-4 py-2 font-medium transition">Annual license</button>
            <button @click="type = 'lifetime'" :class="type === 'lifetime' ? 'bg-white text-ink-950 shadow' : 'text-ink-300 hover:text-white'" class="rounded-xl px-4 py-2 font-medium transition">Lifetime license</button>
        </div>
    </div>
    <p class="mt-3 text-center text-xs text-ink-400" x-show="type === 'annual'">{{ setting('pricing.annual_note') }}</p>
    <p class="mt-3 text-center text-xs text-ink-400" x-show="type === 'lifetime'" x-cloak>{{ setting('pricing.lifetime_note') }}</p>
    @if ($code !== 'USD')
        <p class="mt-2 text-center text-[11px] text-ink-500">Prices in {{ $cur->name }} at today’s rate (1 USD = {{ rtrim(rtrim(number_format($cur->effectiveRate(), 4), '0'), '.') }} {{ $code }}), rounded down in your favour.</p>
    @endif

    <div class="mt-10 grid gap-4 lg:grid-cols-{{ min(3, max(1, $plans->count())) }}">
        @foreach ($plans as $plan)
            @php $featured = $plan->featured; $id = $plan->code; $pr = $prices[$id]; @endphp
            <div class="reveal relative flex flex-col rounded-3xl p-7 {{ $featured ? 'glow-border border border-transparent bg-gradient-to-b from-volt-500/[0.14] to-ink-900/80 shadow-[0_30px_80px_-30px_rgb(124_92_255/.6)]' : 'card' }}">
                @if ($featured)
                    <span class="absolute -top-3 left-7 rounded-full bg-gradient-to-r from-volt-500 to-flare-500 px-3 py-1 text-[11px] font-medium text-white shadow-lg">Most popular</span>
                @endif
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-white">{{ $plan->name }}</h3>
                    <span class="badge badge-ink font-mono !text-[10px]">{{ $plan->domains }}{{ $plan->domains >= 10 ? '+' : '' }} {{ Str::plural('DOMAIN', $plan->domains) }}</span>
                </div>
                <p class="mt-1.5 min-h-10 text-sm text-ink-400">{{ $plan->blurb }}</p>

                <div class="mt-6 min-h-16">
                    <template x-if="total('{{ $id }}') !== null">
                        <div>
                            @if ($showExact && $pr['annual']['discounted'])
                                <div class="text-sm text-ink-500 line-through decoration-coral-400/70" x-show="extra['{{ $id }}'] === 0"
                                     x-text="type === 'annual' ? @js($pr['annual']['exact_display']) : @js($pr['lifetime']['exact_display'] ?? '')"></div>
                            @endif
                            <div class="flex items-baseline gap-1.5">
                                <span class="text-5xl font-semibold tracking-tighter text-white" x-text="fmt(total('{{ $id }}'))">{{ $pr['annual']['display'] }}</span>
                                <span class="text-sm text-ink-400" x-text="type === 'annual' ? '/ year' : 'one-time'">/ year</span>
                            </div>
                        </div>
                    </template>
                    <template x-if="total('{{ $id }}') === null">
                        <div class="flex items-baseline gap-1.5 pt-5"><span class="text-4xl font-semibold tracking-tighter text-white">Custom</span><span class="text-sm text-ink-400">talk to sales</span></div>
                    </template>
                </div>

                <div class="mt-4 flex items-center justify-between rounded-xl border hairline bg-white/[0.02] px-3 py-2">
                    <div class="text-xs"><div class="text-ink-200">Extra domains</div><div class="text-ink-500"><span x-text="fmt(type === 'annual' ? prices['{{ $id }}'].domain_annual : prices['{{ $id }}'].domain_lifetime)"></span> <span x-text="type === 'annual' ? '/ domain / yr' : '/ domain, one-time'"></span></div></div>
                    <div class="flex items-center gap-1">
                        <button @click="extra['{{ $id }}'] = Math.max(0, extra['{{ $id }}'] - 1)" class="grid size-7 place-items-center rounded-lg bg-white/[0.05] text-ink-200 hover:bg-white/10" aria-label="Remove domain">−</button>
                        <span class="w-7 text-center font-mono text-sm text-white" x-text="extra['{{ $id }}']">0</span>
                        <button @click="extra['{{ $id }}']++" class="grid size-7 place-items-center rounded-lg bg-white/[0.05] text-ink-200 hover:bg-white/10" aria-label="Add domain">+</button>
                    </div>
                </div>

                <a href="{{ route('checkout', $id) }}" :href="total('{{ $id }}') === null ? '{{ route('contact') }}' : '{{ route('checkout', $id) }}?billing=' + type + '&domains=' + extra['{{ $id }}']"
                   class="btn mt-5 w-full {{ $featured ? 'btn-primary' : 'btn-outline' }}">
                    <x-icon name="key" class="size-4"/> <span x-text="total('{{ $id }}') === null ? 'Contact sales' : 'Buy {{ $plan->name }}'">Buy {{ $plan->name }}</span>
                </a>
                @if ($plan->offersTrial())
                    <a href="{{ route('trial', $id) }}" class="btn btn-ghost mt-2 w-full text-sm"><x-icon name="sparkles" class="size-4"/> Start {{ $plan->trial_days }}-day free trial</a>
                    <p class="mt-1 text-center text-[11px] text-ink-500">No card needed · {{ $plan->trialSummary() }}</p>
                @endif

                <ul class="mt-7 space-y-3 border-t hairline pt-6 text-sm">
                    @foreach ($plan->features ?? [] as $f)
                        <li class="flex items-start gap-2.5 text-ink-200"><x-icon name="check" class="mt-0.5 size-4 {{ $featured ? 'text-volt-300' : 'text-ink-400' }}" stroke="2.25"/>{{ $f }}</li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>
</div>
