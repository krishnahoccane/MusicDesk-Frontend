@props(['step', 'steps', 'progress' => [], 'title', 'subtitle' => null])
@php
    $keys = array_keys($steps);
    $index = array_search($step, $keys, true);
    $built = is_file(public_path('build/manifest.json'));
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} · Install</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter-tight:400,500,600,700|instrument-serif:400i|jetbrains-mono:400,500&display=swap" rel="stylesheet">
    @if ($built)
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @else
        {{-- Assets not built yet (step 1 reports it): minimal fallback styles. --}}
        <style>
            body{margin:0;background:#07070b;color:#d2d2e0;font-family:'Inter Tight',system-ui,sans-serif}
            .card{background:#0b0b12;border:1px solid #ffffff14;border-radius:16px;padding:24px}
            .input{width:100%;box-sizing:border-box;padding:10px 12px;border-radius:10px;border:1px solid #ffffff22;background:#07070b;color:#fff}
            .btn{padding:10px 16px;border-radius:10px;border:0;background:#7c5cff;color:#fff;cursor:pointer}
            .label{display:block;margin:12px 0 6px;font-size:13px;color:#a9a9c0}
            a{color:#b9a8ff}
        </style>
    @endif
</head>
<body class="min-h-screen bg-ink-950 text-ink-200 antialiased">
    <div aria-hidden="true" class="pointer-events-none fixed inset-0 -z-10 overflow-hidden">
        <div class="absolute -top-40 left-1/2 h-[520px] w-[900px] -translate-x-1/2 rounded-full opacity-[.18] blur-[120px]" style="background: radial-gradient(closest-side, #7c5cff, transparent), radial-gradient(closest-side at 70% 60%, #ff3fd2, transparent)"></div>
    </div>

    <div class="mx-auto grid min-h-screen max-w-6xl gap-8 px-4 py-10 sm:px-6 lg:grid-cols-[280px_1fr] lg:py-16">
        {{-- Stepper --}}
        <aside>
            <div class="flex items-center gap-2.5">
                <span class="grid size-8 place-items-center rounded-[10px]" style="background: conic-gradient(from 210deg, #7c5cff, #ff3fd2, #ffb547, #7c5cff)">
                    <span class="flex h-3.5 items-end gap-[2px]"><span class="h-[45%] w-[2.5px] rounded-full bg-white"></span><span class="h-full w-[2.5px] rounded-full bg-white"></span><span class="h-[70%] w-[2.5px] rounded-full bg-white"></span></span>
                </span>
                <span class="text-[15px] font-semibold tracking-tight text-white">Installation</span>
            </div>
            <p class="mt-3 text-sm text-ink-400">Set up {{ config('app.name') === 'Laravel' ? 'the application' : config('app.name') }} on this server in five steps.</p>

            <ol class="mt-8 space-y-1">
                @foreach ($steps as $key => [$label, $desc])
                    @php
                        $i = array_search($key, $keys, true);
                        $state = $progress[$key] ?? null;
                        $current = $key === $step;
                        $reachable = $state || $current || ($i > 0 && isset($progress[$keys[$i - 1]]));
                    @endphp
                    <li>
                        <a @if ($reachable) href="{{ route('install.step', $key) }}" @endif
                           class="flex items-start gap-3 rounded-xl p-3 transition {{ $current ? 'bg-white/[0.06]' : ($reachable ? 'hover:bg-white/[0.03]' : 'pointer-events-none opacity-50') }}">
                            <span class="mt-0.5 grid size-7 shrink-0 place-items-center rounded-full font-mono text-[11px]
                                {{ $state === 'done' ? 'bg-mint-500/20 text-mint-400' : ($state === 'skipped' ? 'bg-white/10 text-ink-300' : ($current ? 'bg-volt-500 text-white' : 'bg-white/[0.06] text-ink-400')) }}">
                                @if ($state === 'done')<x-icon name="check" class="size-3.5" stroke="3"/>@elseif ($state === 'skipped')–@else{{ $i + 1 }}@endif
                            </span>
                            <span>
                                <span class="block text-sm font-medium {{ $current ? 'text-white' : 'text-ink-200' }}">{{ $label }}@if ($key === 'redis') <span class="font-normal text-ink-500">(optional)</span>@endif</span>
                                <span class="block text-xs text-ink-500">{{ $state === 'skipped' ? 'Skipped' : $desc }}</span>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ol>

            <p class="mt-8 hidden text-xs leading-relaxed text-ink-500 lg:block">Settings are saved to the <span class="font-mono">.env</span> file as you go. You can change them there later. This installer disappears once you create the super admin.</p>
        </aside>

        {{-- Step content --}}
        <main>
            <div class="eyebrow">Step {{ $index + 1 }} of {{ count($steps) }}</div>
            <h1 class="display mt-2 text-3xl sm:text-4xl">{{ $title }}</h1>
            @if ($subtitle)<p class="mt-2 max-w-2xl text-ink-400">{{ $subtitle }}</p>@endif

            @if (session('status'))
                <div class="mt-6 flex items-center gap-3 rounded-xl border border-mint-500/25 bg-mint-500/[0.07] px-4 py-3 text-sm text-mint-400">
                    <x-icon name="check" class="size-4"/> {{ session('status') }}
                </div>
            @endif
            @if ($errors->any())
                <div class="mt-6 rounded-xl border border-coral-500/30 bg-coral-500/[0.07] px-4 py-3 text-sm text-coral-400" role="alert">
                    @foreach ($errors->all() as $e)<div class="flex items-start gap-2"><x-icon name="alert" class="mt-0.5 size-4 shrink-0"/><span>{{ $e }}</span></div>@endforeach
                </div>
            @endif

            <div class="mt-6">{{ $slot }}</div>
        </main>
    </div>
</body>
</html>
