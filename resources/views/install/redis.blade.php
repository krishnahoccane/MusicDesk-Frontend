@php $use = old('use', ['cache', 'session']); @endphp
<x-layouts.installer :step="$step" :steps="$steps" :progress="$progress" title="Connect Redis (optional)" subtitle="Redis makes caching, sessions and background jobs faster. Without it, the app stores them in the database, which works fine for most sites. You can add Redis later in .env.">
    <form method="POST" action="{{ route('install.redis') }}" class="card card-pad space-y-5" x-data="{ busy: false }" @submit="busy = true">
        @csrf
        @if (! $client)
            <div class="rounded-xl border border-amber-glow/25 bg-amber-glow/[0.06] p-4 text-sm text-amber-glow">
                No Redis client is available on this server. Enable the PHP <span class="font-mono">redis</span> extension, or run <span class="font-mono">composer require predis/predis</span>, or skip this step.
            </div>
        @else
            <p class="text-xs text-ink-500">Client: <span class="font-mono text-ink-300">{{ $client }}</span></p>
        @endif

        <div class="grid gap-4 sm:grid-cols-[1fr_120px_100px]">
            <div><label class="label" for="host">Host</label><input id="host" name="host" class="input" value="{{ old('host', $env->get('REDIS_HOST') ?: '127.0.0.1') }}"></div>
            <div><label class="label" for="port">Port</label><input id="port" name="port" type="number" class="input font-mono" value="{{ old('port', $env->get('REDIS_PORT') ?: 6379) }}"></div>
            <div><label class="label" for="db">DB index</label><input id="db" name="db" type="number" min="0" max="15" class="input font-mono" value="{{ old('db', $env->get('REDIS_DB') ?? 0) }}"></div>
            <div><label class="label" for="username">Username <span class="text-ink-500">(Redis 6+ ACL, optional)</span></label><input id="username" name="username" class="input" value="{{ old('username') }}" autocomplete="off"></div>
            <div class="sm:col-span-2"><label class="label" for="password">Password <span class="text-ink-500">(optional)</span></label><input id="password" name="password" type="password" class="input" autocomplete="new-password"></div>
        </div>

        <fieldset>
            <legend class="label">Use Redis for</legend>
            <div class="grid gap-2 sm:grid-cols-3">
                @foreach (['cache' => ['Cache', 'Settings, rates, license lists'], 'session' => ['Sessions', 'Sign-ins'], 'queue' => ['Queue', 'Needs a queue worker running']] as $k => [$l, $d])
                    <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-white/[0.08] p-3 text-sm transition has-[:checked]:border-volt-500/50 has-[:checked]:bg-volt-500/[0.06]">
                        <input type="checkbox" name="use[]" value="{{ $k }}" class="mt-0.5 size-4 accent-volt-500" @checked(in_array($k, $use, true))>
                        <span><span class="block text-ink-100">{{ $l }}</span><span class="text-xs text-ink-500">{{ $d }}</span></span>
                    </label>
                @endforeach
            </div>
        </fieldset>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t hairline pt-5">
            <a href="{{ route('install.step', 'database') }}" class="btn btn-ghost">Back</a>
            <div class="flex gap-2">
                <button name="action" value="skip" formnovalidate class="btn btn-outline">Skip, use the database</button>
                <button name="action" value="save" class="btn btn-primary" :disabled="busy" @disabled(! $client)>
                    <span x-show="!busy">Test & save <x-icon name="arrow-right" class="inline size-4"/></span><span x-show="busy" x-cloak>Connecting…</span>
                </button>
            </div>
        </div>
    </form>
</x-layouts.installer>
