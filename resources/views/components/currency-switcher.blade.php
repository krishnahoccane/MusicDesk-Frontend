@props(['align' => 'right', 'up' => false])
@php
    $currencies = app(\App\Services\Currency\CurrencyService::class)->enabled();
    $current = $currencies[$localeCurrency ?? 'USD'] ?? $currencies->first();
@endphp
@if ($currencies->count() > 1 && $current)
<div x-data="{ o: false }" class="relative" @keydown.escape="o = false">
    <button type="button" @click="o = !o" class="btn btn-ghost btn-sm gap-1.5 font-mono !text-[12px]" aria-label="Change currency" :aria-expanded="o">
        <span class="text-ink-400">{{ trim($current->symbol) }}</span>{{ $current->code }}
        <x-icon name="chevron-down" class="size-3 text-ink-500"/>
    </button>
    <div x-show="o" x-cloak @click.outside="o = false" x-transition.origin.top
         class="absolute {{ $align === 'right' ? 'right-0' : 'left-0' }} {{ $up ? 'bottom-full mb-2' : 'mt-2' }} z-50 max-h-80 w-64 overflow-y-auto rounded-2xl glass !bg-ink-900/95 p-1.5 shadow-2xl shadow-black/60">
        <div class="eyebrow px-2.5 py-2 !text-[10px]">Show prices in</div>
        @foreach ($currencies as $c)
            <form method="POST" action="{{ route('locale.currency') }}">
                @csrf
                <input type="hidden" name="currency" value="{{ $c->code }}">
                <button class="nav-link w-full !py-1.5 {{ $c->code === $current->code ? 'active' : '' }}">
                    <span class="w-10 font-mono text-xs text-ink-400">{{ trim($c->symbol) }}</span>
                    <span class="flex-1 text-left">{{ $c->name }}</span>
                    <span class="font-mono text-[11px] text-ink-500">{{ $c->code }}</span>
                </button>
            </form>
        @endforeach
        <p class="px-2.5 pb-1.5 pt-2 text-[10.5px] leading-relaxed text-ink-500">Prices are set in USD and converted at today’s rate, then rounded down. You’re charged the price shown.</p>
    </div>
</div>
@endif
