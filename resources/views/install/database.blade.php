@php
    $current = old('driver', in_array($env->get('DB_CONNECTION'), ['mysql', 'mariadb', 'pgsql', 'sqlite'], true) ? $env->get('DB_CONNECTION') : (in_array('mysql', $drivers, true) ? 'mysql' : ($drivers[0] ?? 'mysql')));
    $options = [
        'mysql' => ['MySQL', 3306, in_array('mysql', $drivers, true)],
        'mariadb' => ['MariaDB', 3306, in_array('mysql', $drivers, true)],
        'pgsql' => ['PostgreSQL', 5432, in_array('pgsql', $drivers, true)],
        'sqlite' => ['SQLite', null, in_array('sqlite', $drivers, true)],
    ];
@endphp
<x-layouts.installer :step="$step" :steps="$steps" :progress="$progress" title="Connect the database" subtitle="Create an empty database and a user for it in your hosting panel first. The installer then creates all tables and the default currencies, plans and legal pages.">
    <form method="POST" action="{{ route('install.database') }}" class="card card-pad space-y-5"
          x-data="{ driver: @js($current), ports: { mysql: 3306, mariadb: 3306, pgsql: 5432 }, port: @js(old('port', $env->get('DB_PORT') ?: 3306)), busy: false }"
          x-init="$watch('driver', d => { if (ports[d]) port = ports[d] })" @submit="busy = true">
        @csrf
        <div>
            <span class="label">Database type</span>
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                @foreach ($options as $value => [$label, $port, $available])
                    <label class="cursor-pointer {{ $available ? '' : 'pointer-events-none opacity-40' }}">
                        <input type="radio" name="driver" value="{{ $value }}" x-model="driver" class="peer sr-only" @checked($current === $value) @disabled(! $available)>
                        <span class="block rounded-xl border border-white/[0.08] px-3 py-3 text-center text-sm text-ink-300 transition peer-checked:border-volt-500/60 peer-checked:bg-volt-500/10 peer-checked:text-white">
                            {{ $label }}@unless ($available)<span class="block text-[10px] text-ink-500">driver missing</span>@endunless
                        </span>
                    </label>
                @endforeach
            </div>
        </div>

        <div x-show="driver !== 'sqlite'" class="grid gap-4 sm:grid-cols-[1fr_120px]">
            <div><label class="label" for="host">Host</label><input id="host" name="host" class="input" value="{{ old('host', $env->get('DB_HOST') ?: '127.0.0.1') }}" placeholder="127.0.0.1 or localhost" :required="driver !== 'sqlite'"></div>
            <div><label class="label" for="port">Port</label><input id="port" name="port" type="number" x-model="port" class="input font-mono" :required="driver !== 'sqlite'"></div>
            <div class="sm:col-span-2"><label class="label" for="database">Database name</label><input id="database" name="database" class="input" value="{{ old('database', in_array($env->get('DB_CONNECTION'), ['mysql', 'mariadb', 'pgsql'], true) ? $env->get('DB_DATABASE') : '') }}" placeholder="musicdesk" :required="driver !== 'sqlite'"></div>
            <div><label class="label" for="username">Username</label><input id="username" name="username" class="input" value="{{ old('username', $env->get('DB_USERNAME')) }}" autocomplete="off" :required="driver !== 'sqlite'"></div>
            <div><label class="label" for="password">Password</label><input id="password" name="password" type="password" class="input" autocomplete="new-password"></div>
        </div>

        <div x-show="driver === 'sqlite'" x-cloak>
            <label class="label" for="path">Database file</label>
            <input id="path" name="path" class="input font-mono text-sm" value="{{ old('path', 'database/database.sqlite') }}" :required="driver === 'sqlite'">
            <p class="mt-1.5 text-xs text-ink-500">Relative to the application folder. Created if it doesn’t exist. Fine for small sites; use MySQL or PostgreSQL for production traffic.</p>
        </div>

        <div class="rounded-xl border hairline bg-white/[0.02] p-4 text-xs leading-relaxed text-ink-400">
            The installer runs the migrations (<span class="font-mono">php artisan migrate</span>). It never drops existing tables, so it’s safe to run against a database that already has this application’s tables.
        </div>

        <div class="flex items-center justify-between border-t hairline pt-5">
            <a href="{{ route('install.step', 'requirements') }}" class="btn btn-ghost">Back</a>
            <button class="btn btn-primary" :disabled="busy">
                <span x-show="!busy">Connect & create tables <x-icon name="arrow-right" class="inline size-4"/></span>
                <span x-show="busy" x-cloak>Setting up the database…</span>
            </button>
        </div>
    </form>
</x-layouts.installer>
