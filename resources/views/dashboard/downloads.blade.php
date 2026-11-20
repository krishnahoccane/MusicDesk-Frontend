@php
    use App\Support\Demo;
    $latest = $releases[0];
    $kinds = ['new' => ['New', 'badge-volt'], 'improved' => ['Improved', 'badge-sky'], 'fixed' => ['Fixed', 'badge-mint'], 'security' => ['Security', 'badge-coral']];
@endphp
<x-layouts.app title="Downloads" subtitle="Get the latest LabelDesk package, read the changelog and update your installs.">
    <x-slot:eyebrow>Portal · Software</x-slot:eyebrow>

    <div class="grid gap-4 xl:grid-cols-3" x-data="{ lic: @js($licenses[0]['id'] ?? ''), expired: @js(collect($licenses)->filter(fn ($l) => $l['support_expired'] ?? false)->pluck('id')->values()) }">
        {{-- Latest --}}
        <div class="glow-border relative overflow-hidden rounded-2xl border border-transparent bg-ink-900/80 p-6 sm:p-8 xl:col-span-2">
            <div class="absolute -right-24 -top-24 size-72 rounded-full bg-volt-500/20 blur-3xl"></div>
            <div class="relative">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="badge badge-mint badge-dot">Latest stable</span>
                    <span class="text-xs text-ink-400">Released {{ $latest['date'] }}</span>
                </div>
                <div class="mt-4 flex flex-wrap items-end gap-x-4 gap-y-2">
                    <h2 class="display text-4xl sm:text-5xl">LabelDesk {{ $latest['version'] }}</h2>
                    <span class="mb-1.5 font-mono text-xs text-ink-500">labeldesk-{{ $latest['version'] }}.zip · 48.2 MB</span>
                </div>

                <div class="mt-6 grid gap-3 sm:grid-cols-[1fr_auto]">
                    <div>
                        <label class="label">Download for license</label>
                        <select x-model="lic" class="input">
                            @foreach ($licenses as $l)
                                <option value="{{ $l['id'] }}">{{ $l['id'] }} · {{ $l['tier'] }} {{ $l['type'] }}{{ ($l['support_expired'] ?? false) ? ' (updates expired)' : '' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex items-end">
                        <button class="btn btn-white w-full sm:w-auto" :disabled="!lic || expired.includes(lic)" @click="toast('Download started: labeldesk-{{ $latest['version'] }}.zip')"><x-icon name="download" class="size-4"/> Download package</button>
                    </div>
                </div>
                <div x-show="expired.includes(lic)" x-cloak x-transition class="mt-3 flex flex-col gap-3 rounded-xl border border-amber-glow/20 bg-amber-glow/[0.05] p-3 text-sm sm:flex-row sm:items-center">
                    <span class="flex-1 text-ink-200">This license’s update period has ended. It can still download the versions released before then.</span>
                    <a href="{{ route('dashboard.licenses') }}" class="btn btn-sm shrink-0 bg-amber-glow text-ink-950">Renew updates (40% off)</a>
                </div>

                <div class="mt-6 rounded-xl border hairline bg-ink-950/60 p-3" x-data="{ sum: '9f3a1c7e2b4d8f60a5e1c2d7b8e94f0aa1c3e5d7f9b2c4e6a8d0f1e3c5b7a9d1' }">
                    <div class="flex items-center justify-between gap-3"><span class="text-xs text-ink-500">SHA-256 checksum</span><button class="text-xs text-volt-300 hover:text-white" @click="$copy(sum)">Copy</button></div>
                    <code class="mt-1 block truncate font-mono text-[12px] text-ink-300" x-text="sum"></code>
                </div>

                <div class="mt-6 grid gap-4 sm:grid-cols-3">
                    @foreach ([['Server', 'PHP 8.2+ · Nginx or Apache'], ['Database', 'MySQL 8 / PostgreSQL 15'], ['Extras', 'Redis, cron, 2 GB RAM']] as [$k, $v])
                        <div><div class="text-[11px] text-ink-500">{{ $k }}</div><div class="mt-0.5 text-sm text-ink-100">{{ $v }}</div></div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Update instructions --}}
        <div class="card card-pad flex flex-col">
            <h2 class="font-semibold text-white">Update an existing install</h2>
            <p class="mt-1 text-sm text-ink-400">One click in your LabelDesk admin panel (Settings → Updates), or from the command line:</p>
            <div class="mt-4 space-y-2" x-data>
                @foreach (['php artisan down', 'php artisan labeldesk:update --version='.$latest['version'], 'php artisan migrate --force', 'php artisan up'] as $cmd)
                    <div class="group flex items-center gap-2 rounded-lg border hairline bg-ink-950 px-3 py-2">
                        <span class="font-mono text-xs text-ink-500">$</span>
                        <code class="flex-1 truncate font-mono text-[12px] text-ink-200">{{ $cmd }}</code>
                        <button class="text-ink-500 opacity-0 transition hover:text-white group-hover:opacity-100" @click="$copy(@js($cmd))" aria-label="Copy command"><x-icon name="copy" class="size-3.5"/></button>
                    </div>
                @endforeach
            </div>
            <p class="mt-4 text-xs text-ink-500">Back up your database first. Test on your staging domain before production.</p>
            <div class="mt-auto grid grid-cols-2 gap-2 pt-6">
                <a href="#" class="btn btn-outline btn-sm"><x-icon name="file" class="size-3.5"/> Install guide</a>
                <a href="#" class="btn btn-outline btn-sm"><x-icon name="link" class="size-3.5"/> API docs</a>
            </div>
        </div>
    </div>

    {{-- Changelog --}}
    <div id="changelog" class="mt-4 grid scroll-mt-24 gap-4 xl:grid-cols-3">
        <div class="card card-pad xl:col-span-2">
            <h2 class="font-semibold text-white">Changelog</h2>
            <ol class="relative mt-6 space-y-8 border-l border-white/[0.08] pl-6">
                @foreach ($releases as $r)
                    <li class="relative">
                        <span class="absolute -left-[31px] top-1 grid size-3.5 place-items-center rounded-full ring-4 ring-ink-900 {{ $loop->first ? 'bg-volt-500' : 'bg-ink-600' }}"></span>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-mono text-sm font-medium text-white">v{{ $r['version'] }}</span>
                            @if ($r['tag'])<span class="badge badge-mint !text-[10px]">{{ $r['tag'] }}</span>@endif
                            <span class="text-xs text-ink-500">{{ $r['date'] }}</span>
                        </div>
                        <ul class="mt-3 space-y-2">
                            @foreach ($r['notes'] as [$kind, $note])
                                <li class="flex items-start gap-3 text-sm"><span class="badge {{ $kinds[$kind][1] }} w-[74px] justify-center !text-[10px]">{{ $kinds[$kind][0] }}</span><span class="text-ink-200">{{ $note }}</span></li>
                            @endforeach
                        </ul>
                    </li>
                @endforeach
            </ol>
        </div>

        <div class="card">
            <div class="p-5 sm:p-6"><h2 class="font-semibold text-white">Previous versions</h2><p class="text-sm text-ink-400">For rollbacks or matching an older install</p></div>
            <div class="divide-y divide-white/[0.04] border-t hairline">
                @foreach (array_merge(array_slice(array_column($releases, 'version'), 1), ['4.0.2', '3.8.0']) as $v)
                    <div class="flex items-center justify-between px-5 py-3 sm:px-6">
                        <span class="font-mono text-sm text-ink-100">v{{ $v }}</span>
                        <button class="btn btn-ghost btn-sm" @click="toast('Download started: labeldesk-{{ $v }}.zip')"><x-icon name="download" class="size-3.5"/> .zip</button>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</x-layouts.app>
