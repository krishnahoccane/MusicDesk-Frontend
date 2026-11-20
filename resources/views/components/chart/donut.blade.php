@props(['data' => [], 'size' => 168, 'label' => '', 'value' => ''])
@php
    $r = 15.9155; $offset = 25; $gap = 1.2;
@endphp
<div {{ $attributes->merge(['class' => 'relative shrink-0']) }} style="width: {{ $size }}px; height: {{ $size }}px">
    <svg viewBox="0 0 42 42" class="size-full -rotate-0">
        <circle cx="21" cy="21" r="{{ $r }}" fill="none" stroke="white" stroke-opacity=".05" stroke-width="4.2"/>
        @foreach ($data as $d)
            <circle cx="21" cy="21" r="{{ $r }}" fill="none" stroke="{{ $d['color'] }}" stroke-width="4.2"
                    stroke-dasharray="{{ max($d['share'] - $gap, 0.1) }} {{ 100 - max($d['share'] - $gap, 0.1) }}"
                    stroke-dashoffset="{{ $offset }}" stroke-linecap="round"/>
            @php $offset -= $d['share']; @endphp
        @endforeach
    </svg>
    <div class="absolute inset-0 grid place-items-center text-center">
        <div>
            <div class="text-2xl font-semibold tracking-tight text-white">{{ $value }}</div>
            <div class="eyebrow mt-0.5 !text-[9.5px]">{{ $label }}</div>
        </div>
    </div>
</div>
