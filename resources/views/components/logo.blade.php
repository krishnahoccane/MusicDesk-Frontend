@props(['product' => null, 'size' => 'md'])
@php
    $box = $size === 'sm' ? 'size-7 rounded-lg' : 'size-8 rounded-[10px]';
    $name = $product ?? setting('branding.site_name');
    $suffix = $product ? '' : setting('branding.site_suffix');
    $image = ! $product ? app(\App\Support\Settings::class)->image('branding.logo') : null;
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2.5']) }}>
    @if ($image)
        <img src="{{ $image }}" alt="" class="{{ $box }} object-contain">
    @else
        <span class="relative {{ $box }} grid place-items-center overflow-hidden"
              style="background: conic-gradient(from 210deg, var(--color-volt-500), var(--color-flare-500), var(--color-amber-glow), var(--color-volt-500));">
            <span class="absolute inset-[1.5px] rounded-[inherit] bg-ink-950/85"></span>
            <span class="relative flex h-3.5 items-end gap-[2px]">
                <span class="h-[45%] w-[2.5px] rounded-full bg-white"></span>
                <span class="h-full w-[2.5px] rounded-full bg-white"></span>
                <span class="h-[70%] w-[2.5px] rounded-full bg-white"></span>
                <span class="h-[30%] w-[2.5px] rounded-full bg-white/70"></span>
            </span>
        </span>
    @endif
    <span class="text-[15px] font-semibold tracking-tight text-white">{{ $name }}@if ($suffix)<span class="font-normal text-ink-500">{{ $suffix }}</span>@endif</span>
</span>
