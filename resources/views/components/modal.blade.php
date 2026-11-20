@props(['event', 'title', 'subtitle' => null, 'width' => 'max-w-lg'])
{{-- Generic Alpine modal. Opens on window event `$event`; event detail is available as `data`. --}}
<div x-data="{ open: false, data: {} }" x-on:{{ $event }}.window="open = true; data = $event.detail || {}" @keydown.escape.window="open = false"
     x-show="open" x-cloak class="fixed inset-0 z-[60] grid place-items-center overflow-y-auto p-4">
    <div x-show="open" x-transition.opacity class="fixed inset-0 bg-black/70 backdrop-blur-sm" @click="open = false"></div>
    <div x-show="open" x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="opacity-0 scale-95 translate-y-2"
         x-transition:leave="transition duration-150" x-transition:leave-end="opacity-0 scale-95"
         {{ $attributes->merge(['class' => "relative w-full $width rounded-3xl border border-white/10 bg-ink-900 p-6 shadow-2xl shadow-black/70"]) }}>
        <div class="flex items-start justify-between gap-4">
            <div>
                <h3 class="text-lg font-semibold text-white">{{ $title }}</h3>
                @if ($subtitle)<p class="mt-0.5 text-sm text-ink-400">{!! $subtitle !!}</p>@endif
            </div>
            <button @click="open = false" class="btn btn-ghost btn-sm !p-1.5" aria-label="Close"><x-icon name="x"/></button>
        </div>
        {{ $slot }}
    </div>
</div>
