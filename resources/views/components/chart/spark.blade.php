@props(['data' => [], 'color' => '#7c5cff', 'w' => 96, 'h' => 32])
@php
    $max = max($data); $min = min($data); $n = count($data);
    $pts = collect($data)->map(fn ($v, $i) => round($i / ($n - 1) * $w, 1).','.round($h - 2 - ($h - 4) * (($v - $min) / max($max - $min, 1)), 1))->implode(' ');
    $gid = 's'.uniqid();
@endphp
<svg {{ $attributes }} width="{{ $w }}" height="{{ $h }}" viewBox="0 0 {{ $w }} {{ $h }}" fill="none">
    <defs><linearGradient id="{{ $gid }}" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="{{ $color }}" stop-opacity=".35"/><stop offset="1" stop-color="{{ $color }}" stop-opacity="0"/></linearGradient></defs>
    <polygon points="0,{{ $h }} {{ $pts }} {{ $w }},{{ $h }}" fill="url(#{{ $gid }})"/>
    <polyline points="{{ $pts }}" stroke="{{ $color }}" stroke-width="1.75" stroke-linejoin="round" stroke-linecap="round"/>
</svg>
