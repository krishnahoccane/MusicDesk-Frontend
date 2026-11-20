@props(['label', 'value', 'change' => null, 'icon' => null, 'spark' => null, 'color' => '#7c5cff', 'hint' => 'vs prev. 30d'])
<div {{ $attributes->merge(['class' => 'card card-pad relative overflow-hidden group']) }}>
    <div class="absolute -right-10 -top-10 size-32 rounded-full opacity-0 blur-3xl transition duration-500 group-hover:opacity-40" style="background: {{ $color }}"></div>
    <div class="flex items-center justify-between">
        <span class="text-[13px] text-ink-400">{{ $label }}</span>
        @if ($icon)
            <span class="grid size-8 place-items-center rounded-lg bg-white/[0.04] ring-1 ring-white/[0.06]" style="color: {{ $color }}">
                <x-icon :name="$icon" class="size-4"/>
            </span>
        @endif
    </div>
    <div class="mt-3 flex items-end justify-between gap-3">
        <div>
            <div class="text-[28px] font-semibold leading-none tracking-tight text-white">{{ $value }}</div>
            @if (! is_null($change))
                <div class="mt-2.5 flex items-center gap-1.5 whitespace-nowrap text-xs">
                    <span class="{{ $change >= 0 ? 'text-mint-400' : 'text-coral-400' }} font-medium">{{ $change >= 0 ? '↑' : '↓' }} {{ abs($change) }}%</span>
                    <span class="text-ink-500">{{ $hint }}</span>
                </div>
            @endif
        </div>
        @if ($spark)
            <x-chart.spark :data="$spark" :color="$color" class="mb-1 h-8 w-auto min-w-0 max-w-[40%]" preserveAspectRatio="none"/>
        @endif
    </div>
</div>
