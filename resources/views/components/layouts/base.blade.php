@props(['title' => null, 'bodyClass' => ''])
<!DOCTYPE html>
<html lang="en" class="scroll-smooth overflow-x-clip">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php $site = app(\App\Support\Settings::class); $siteName = setting('branding.site_name'); @endphp
    <meta name="theme-color" content="{{ setting('branding.color_background') }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="{{ $siteName }} builds {{ setting('branding.product_name') }}, white-label music distribution and royalty software that labels and distributors run under their own brand.">
    <title>{{ $title ? $title.' · '.$siteName : $siteName.': White-label software for music businesses' }}</title>
    @if ($og = $site->image('branding.og_image'))<meta property="og:image" content="{{ $og }}">@endif
    @if ($fav = $site->image('branding.favicon'))<link rel="icon" href="{{ $fav }}">@else
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Cdefs%3E%3ClinearGradient id='g' x1='0' y1='0' x2='1' y2='1'%3E%3Cstop offset='0' stop-color='%237c5cff'/%3E%3Cstop offset='1' stop-color='%23ff3fd2'/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width='32' height='32' rx='8' fill='url(%23g)'/%3E%3Cg fill='white'%3E%3Crect x='8' y='15' width='3' height='8' rx='1.5'/%3E%3Crect x='13' y='9' width='3' height='14' rx='1.5'/%3E%3Crect x='18' y='12' width='3' height='11' rx='1.5'/%3E%3Crect x='23' y='17' width='3' height='6' rx='1.5'/%3E%3C/g%3E%3C/svg%3E">@endif
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="{{ $site->fontsUrl() }}" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {{-- Admin-managed theme (/admin/settings/branding) --}}
    <style>{!! $site->themeCss() !!}</style>
</head>
<body {{ $attributes->merge(['class' => 'noise min-h-screen overflow-x-clip '.$bodyClass]) }}>
    {{ $slot }}

    @if (session('toast'))
        <div x-data x-init="$nextTick(() => toast(@js(session('toast')), @js(session('toast_tone'))))"></div>
    @endif
    @if ($errors->any())
        <div x-data x-init="$nextTick(() => toast(@js($errors->first()), 'danger'))"></div>
    @endif

    {{-- Toasts --}}
    <div x-data class="pointer-events-none fixed bottom-5 left-1/2 z-[70] flex -translate-x-1/2 flex-col items-center gap-2">
        <template x-for="t in $store.toasts.items" :key="t.id">
            <div x-transition.opacity.duration.250ms
                 class="pointer-events-auto flex items-center gap-2.5 rounded-xl glass px-4 py-2.5 text-sm text-white shadow-2xl shadow-black/50">
                <span class="grid size-5 place-items-center rounded-full"
                      :class="t.tone === 'danger' ? 'bg-coral-500/20 text-coral-400' : 'bg-mint-500/20 text-mint-400'">
                    <svg class="size-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg>
                </span>
                <span x-text="t.message"></span>
            </div>
        </template>
    </div>
</body>
</html>
