@php
    use App\Models\License;
    $latest = $releases[0] ?? null;
    $kinds = ['new' => ['New', 'badge-volt'], 'improved' => ['Improved', 'badge-sky'], 'fixed' => ['Fixed', 'badge-mint'], 'security' => ['Security', 'badge-coral']];
    $usable = collect($licenses)->reject(fn ($l) => in_array($l['status'], ['suspended', 'revoked'], true))->values();
    // Per license: last date it may download (end of updates period), as a timestamp; null = any version.
    $entitled = License::query()->whereIn('uuid', $usable->pluck('uid'))->get()->mapWithKeys(fn ($l) => [$l->uuid => $l->support_until?->timestamp]);
    $size = fn ($b) => $b ? number_format($b / 1048576, 1).' MB' : null;
    $req = $latest['requirements'] ?? [];
@endphp
<x-layouts.app title="Downloads" subtitle="Get the latest {{ setting('branding.product_name') }} package, read the changelog and update your installs.">
    <x-slot:eyebrow>Portal · Software</x-slot:eyebrow>

    @if (! $latest)
        <div class="card grid place-items-center px-6 py-16 text-center">
            <x-icon name="download" class="size-8 text-ink-500"/>
            <div class="mt-4 font-medium text-white">No releases published yet</div>
            <p class="mt-1 text-sm text-ink-400">New versions appear here as soon as they’re published.</p>
        </div>
    @else
    <div x-data="{
            lic: @js($usable[0]['uid'] ?? ''),
            until: {{ json_encode($entitled) }},
            base: @js(url('/dashboard/downloads')),
            allowed(releasedAt) { const u = this.until[this.lic]; return this.lic && (u === null || u === undefined || releasedAt <= u) },
            url(id) { return this.base + '/' + id + '?license=' + encodeURIComponent(this.lic) },
         }">
        <div class="grid gap-4 xl:grid-cols-3">
            {{-- Latest --}}
            <div class="glow-border relative overflow-hidden rounded-2xl border border-transparent bg-ink-900/80 p-6 sm:p-8 xl:col-span-2">
                <div class="absolute -right-24 -top-24 size-72 rounded-full bg-volt-500/20 blur-3xl"></div>
                <div class="relative">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="badge badge-mint badge-dot">Latest stable</span>
                        <span class="text-xs text-ink-400">Released {{ $latest['date'] }}</span>
                    </div>
                    <div class="mt-4 flex flex-wrap items-end gap-x-4 gap-y-2">
                        <h2 class="display text-4xl sm:text-5xl">{{ setting('branding.product_name') }} {{ $latest['version'] }}</h2>
                        <span class="mb-1.5 font-mono text-xs text-ink-500">labeldesk-{{ $latest['version'] }}.zip{{ $size($latest['size']) ? ' · '.$size($latest['size']) : '' }}</span>
                    </div>

                    @if ($usable->isEmpty())
                        <p class="mt-6 rounded-xl border border-amber-glow/20 bg-amber-glow/[0.05] p-4 text-sm text-ink-200">You need an active license to download. <a href="{{ route('pricing') }}" class="text-volt-300 underline">Buy a license</a>.</p>
                    @else
                        <div class="mt-6 grid gap-3 sm:grid-cols-[1fr_auto]">
                            <div>
                                <label class="label" for="dl_license">Download with license</label>
                                <select id="dl_license" x-model="lic" class="input">
                                    @foreach ($usable as $l)
                                        <option value="{{ $l['uid'] }}">{{ $l['id'] }} · {{ $l['tier'] }} {{ $l['type'] }}{{ $l['support_expired'] ? ' (updates ended '.$l['support_until'].')' : '' }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="flex items-end">
                                @if ($latest['has_file'])
                                    <a :href="allowed({{ $latest['released_at']->timestamp }}) ? url({{ $latest['id'] }}) : null" :class="! allowed({{ $latest['released_at']->timestamp }}) && 'pointer-events-none opacity-40'" class="btn btn-white w-full sm:w-auto"><x-icon name="download" class="size-4"/> Download package</a>
                                @else
                                    <span class="btn btn-outline pointer-events-none w-full opacity-60 sm:w-auto">Package coming soon</span>
                                @endif
                            </div>
                        </div>
                        <div x-show="! allowed({{ $latest['released_at']->timestamp }})" x-cloak x-transition class="mt-3 flex flex-col gap-3 rounded-xl border border-amber-glow/20 bg-amber-glow/[0.05] p-3 text-sm sm:flex-row sm:items-center">
                            <span class="flex-1 text-ink-200">This license’s updates period ended before this release. It can still download earlier versions below.</span>
                            <a :href="@js(url('/dashboard/licenses')) + '/' + lic + '/buy/updates_renewal'" class="btn btn-sm shrink-0 bg-amber-glow text-ink-950">Renew updates (40% off)</a>
                        </div>
                    @endif

                    @if ($latest['sha256'])
                        <div class="mt-6 rounded-xl border hairline bg-ink-950/60 p-3" x-data="{ sum: @js($latest['sha256']) }">
                            <div class="flex items-center justify-between gap-3"><span class="text-xs text-ink-500">SHA-256 checksum</span><button class="text-xs text-volt-300 hover:text-white" @click="$copy(sum)">Copy</button></div>
                            <code class="mt-1 block truncate font-mono text-[12px] text-ink-300" x-text="sum"></code>
                        </div>
                    @endif

                    <div class="mt-6 grid gap-4 sm:grid-cols-3">
                        @foreach (['php' => 'Server', 'database' => 'Database', 'extras' => 'Extras'] as $k => $label)
                            <div><div class="text-[11px] text-ink-500">{{ $label }}</div><div class="mt-0.5 text-sm text-ink-100">{{ $req[$k] ?? match ($k) { 'php' => 'PHP 8.2+ · Nginx or Apache', 'database' => 'MySQL 8 / PostgreSQL 15', default => 'Redis, cron, 2 GB RAM' } }}</div></div>
                        @endforeach
                    </div>
                    @if ($latest['requires_php'] ?? null)
                        <p class="mt-3 text-xs text-ink-500">Minimum PHP version: <span class="font-mono text-ink-300">{{ $latest['requires_php'] }}</span></p>
                    @endif
                </div>
            </div>

            {{-- Update instructions --}}
            <div class="card card-pad flex flex-col">
                <h2 class="font-semibold text-white">Update an existing install</h2>
                <p class="mt-1 rounded-lg border border-volt-500/20 bg-volt-500/[0.06] p-3 text-sm text-ink-200">{{ setting('branding.product_name') }} can install updates itself under <b class="font-medium text-white">Administration → Updates</b>. Automatic updates are off until you switch them on, separately for {{ setting('branding.product_name') }} and each add-on. If your hosting does not let PHP write to the {{ setting('branding.product_name') }} folder, upload the new files by FTP; the database update then runs by itself.</p>
                <p class="mt-3 text-sm text-ink-400">To update by hand, upload the new files over your install, then run <b class="font-medium text-ink-200">Administration → System → Upgrade database</b> in {{ setting('branding.product_name') }}, or from the command line:</p>
                <div class="mt-4 space-y-2">
                    @foreach (['php artisan down', 'php artisan labeldesk:upgrade', 'php artisan up'] as $cmd)
                        <div class="group flex items-center gap-2 rounded-lg border hairline bg-ink-950 px-3 py-2">
                            <span class="font-mono text-xs text-ink-500">$</span>
                            <code class="flex-1 truncate font-mono text-[12px] text-ink-200">{{ $cmd }}</code>
                            <button class="text-ink-500 opacity-0 transition hover:text-white group-hover:opacity-100" @click="$copy(@js($cmd))" aria-label="Copy command"><x-icon name="copy" class="size-3.5"/></button>
                        </div>
                    @endforeach
                </div>
                <p class="mt-4 text-xs text-ink-500">Back up your database first. Test on your staging domain before production. Installs check in with the license server every 24 hours.</p>
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
                                @if ($r['tag'])<span class="badge {{ $r['tag'] === 'Beta' ? 'badge-amber' : 'badge-mint' }} !text-[10px]">{{ $r['tag'] }}</span>@endif
                                <span class="text-xs text-ink-500">{{ $r['date'] }}</span>
                            </div>
                            <ul class="mt-3 space-y-2">
                                @foreach ($r['notes'] as [$kind, $note])
                                    <li class="flex items-start gap-3 text-sm"><span class="badge {{ ($kinds[$kind] ?? $kinds['new'])[1] }} w-[74px] justify-center !text-[10px]">{{ ($kinds[$kind] ?? $kinds['new'])[0] }}</span><span class="text-ink-200">{{ $note }}</span></li>
                                @endforeach
                            </ul>
                        </li>
                    @endforeach
                </ol>
            </div>

            <div class="card">
                <div class="p-5 sm:p-6"><h2 class="font-semibold text-white">All versions</h2><p class="text-sm text-ink-400">For rollbacks or matching an older install</p></div>
                <div class="divide-y divide-white/[0.04] border-t hairline">
                    @foreach ($releases as $r)
                        <div class="flex items-center justify-between px-5 py-3 sm:px-6">
                            <span class="font-mono text-sm text-ink-100">v{{ $r['version'] }} <span class="text-xs text-ink-500">{{ $r['date'] }}</span></span>
                            @if ($r['has_file'])
                                <a :href="allowed({{ $r['released_at']->timestamp }}) ? url({{ $r['id'] }}) : null" :class="! allowed({{ $r['released_at']->timestamp }}) && 'pointer-events-none opacity-30'" class="btn btn-ghost btn-sm"><x-icon name="download" class="size-3.5"/> .zip</a>
                            @else
                                <span class="text-xs text-ink-500">not uploaded</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        @if (! empty($addonDownloads))
            <div id="addons" class="mt-4 card">
                <div class="p-5 sm:p-6"><h2 class="font-semibold text-white">Add-on packages</h2><p class="text-sm text-ink-400">Upload the .zip in {{ setting('branding.product_name') }} under Administration › Add-ons, then activate it with the add-on’s own key (Licenses › Add-ons). Packages are signed by MusicDesk; don’t unzip or change them.</p></div>
                <div class="divide-y divide-white/[0.04] border-t hairline">
                    @foreach ($addonDownloads as $a)
                        @foreach ($a['releases'] as $i => $r)
                            <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 sm:px-6">
                                <span class="text-sm text-ink-100">{{ $a['name'] }} <span class="font-mono">v{{ $r['version'] }}</span>@if ($i === 0) <span class="badge badge-mint !text-[10px]">Latest</span>@endif <span class="text-xs text-ink-500">{{ $r['date'] }} · key {{ $a['number'] }} for {{ $a['parent'] }}</span></span>
                                @if (! $r['allowed'])
                                    <span class="text-xs text-amber-glow">Outside this add-on’s updates period</span>
                                @elseif ($r['has_file'])
                                    <a href="{{ route('dashboard.downloads.file', [$r['id'], 'license' => $a['uid']]) }}" class="btn btn-ghost btn-sm"><x-icon name="download" class="size-3.5"/> .zip</a>
                                @else
                                    <span class="text-xs text-ink-500">not uploaded</span>
                                @endif
                            </div>
                        @endforeach
                    @endforeach
                </div>
            </div>
        @endif
    </div>
    @endif
</x-layouts.app>
