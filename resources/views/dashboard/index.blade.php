@php
    use App\Support\Demo;
    $latest = \App\Models\Release::latestVersion();
    $installs = collect($licenses)->flatMap(fn ($l) => collect($l['installations'])->map(fn ($i) => $i + ['license' => $l['id'], 'tier' => $l['tier']]));
    $outdated = $installs->where('version', '!=', $latest)->count();
    $domainsUsed = array_sum(array_column($licenses, 'domains_used'));
    $domainsTotal = array_sum(array_column($licenses, 'domains'));
    $latestRelease = \App\Models\Release::latest();
    $release = $latestRelease ? ['version' => $latestRelease->version, 'date' => local_time($latestRelease->released_at)->format('M d, Y'), 'notes' => array_map(fn ($n) => [$n['kind'] ?? 'new', $n['text'] ?? ''], $latestRelease->notes ?? [])] : null;
    $expiring = collect($licenses)->where('status', 'expiring')->sortBy('days_left')->first();
    $next = collect($licenses)->filter(fn ($l) => $l['renews'])->sortBy('days_left')->first();
    $priceOf = fn ($l) => isset($l['price_currency']) ? fmt_money($l['price'], $l['price_currency']) : money($l['price'])['display'];
    $firstName = Str::before(auth()->user()->name, ' ');
@endphp
<x-layouts.app title="Overview" subtitle="Your LabelDesk licenses, installations and account at a glance.">
    <x-slot:eyebrow>{{ local_time(now())->format('l, F j') }}</x-slot:eyebrow>
    <x-slot:heading>Welcome back, {{ $firstName }}</x-slot:heading>
    <x-slot:actions>
        <a href="{{ route('dashboard.downloads') }}" class="btn btn-outline btn-sm"><x-icon name="download" class="size-3.5"/> Download {{ $latest }}</a>
        <a href="{{ route('dashboard.support') }}" class="btn btn-outline btn-sm"><x-icon name="headphones" class="size-3.5"/> Get help</a>
    </x-slot:actions>

    {{-- Alerts --}}
    @if (empty($licenses))
        <div class="mb-4 flex flex-col items-start gap-4 rounded-2xl border border-volt-500/25 bg-volt-500/[0.06] p-5 sm:flex-row sm:items-center">
            <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-volt-500/15 text-volt-300"><x-icon name="key" class="size-5"/></span>
            <div class="flex-1"><div class="font-medium text-white">Get your first {{ setting('branding.product_name') }} license</div><div class="text-sm text-ink-400">Your organisation is set up. Buy a license to get your key and download the software.</div></div>
            <a href="{{ route('pricing') }}" class="btn btn-primary">See licenses</a>
        </div>
    @endif
    <div class="mb-4 grid gap-3 lg:grid-cols-2">
        @if ($expiring)
        <div class="flex items-center gap-4 rounded-2xl border border-amber-glow/25 bg-amber-glow/[0.06] p-4">
            <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-amber-glow/15 text-amber-glow"><x-icon name="alert" class="size-5"/></span>
            <div class="min-w-0 flex-1"><div class="text-sm font-medium text-white">{{ $expiring['id'] }} expires in {{ $expiring['days_left'] }} days</div><div class="truncate text-xs text-ink-400">{{ $expiring['installations'][0]['domain'] ?? 'This license' }} stops getting updates and support on {{ $expiring['renews'] }}</div></div>
            <a href="{{ route('dashboard.licenses') }}" class="btn btn-sm shrink-0 bg-amber-glow text-ink-950 hover:brightness-110">Renew</a>
        </div>
        @endif
        @if ($outdated > 0)
        <div class="flex items-center gap-4 rounded-2xl border border-volt-500/25 bg-volt-500/[0.06] p-4">
            <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-volt-500/15 text-volt-300"><x-icon name="refresh" class="size-5"/></span>
            <div class="min-w-0 flex-1"><div class="text-sm font-medium text-white">{{ $outdated }} installations can update to {{ $latest }}</div><div class="truncate text-xs text-ink-400">Multi-currency statements, DDEX 4.3 and theme editor improvements</div></div>
            <a href="{{ route('dashboard.downloads') }}" class="btn btn-primary btn-sm shrink-0">What’s new</a>
        </div>
        @endif
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat label="Active licenses" value="{{ count($licenses) }}" icon="key" color="#ff3fd2"/>
        <div class="card card-pad">
            <div class="flex items-center justify-between text-[13px] text-ink-400">Domains in use <x-icon name="globe" class="size-4 text-volt-300"/></div>
            <div class="mt-3 text-[28px] font-semibold leading-none tracking-tight text-white">{{ $domainsUsed }}<span class="text-lg text-ink-500"> / {{ $domainsTotal }}</span></div>
            <div class="mt-4 h-1.5 overflow-hidden rounded-full bg-white/[0.06]"><div class="h-full rounded-full bg-gradient-to-r from-volt-500 to-flare-500" style="width: {{ $domainsTotal ? round($domainsUsed / $domainsTotal * 100) : 0 }}%"></div></div>
        </div>
        <div class="card card-pad">
            <div class="flex items-center justify-between text-[13px] text-ink-400">Installations <x-icon name="layers" class="size-4 text-sky-glow"/></div>
            <div class="mt-3 text-[28px] font-semibold leading-none tracking-tight text-white">{{ $installs->count() }}</div>
            <div class="mt-2.5 text-xs"><span class="text-amber-glow">{{ $outdated }} need updating</span> <span class="text-ink-500">· {{ $installs->where('online', true)->count() }} online</span></div>
        </div>
        <div class="card card-pad">
            <div class="flex items-center justify-between text-[13px] text-ink-400">Next renewal <x-icon name="calendar" class="size-4 text-mint-400"/></div>
            <div class="mt-3 text-[28px] font-semibold leading-none tracking-tight text-white">{{ $next ? Str::beforeLast($next['renews'], ',') : '—' }}</div>
            <div class="mt-2.5 text-xs text-ink-500">{{ $next ? $next['id'].' · '.$next['tier'].' · '.$priceOf($next) : 'No annual licenses' }}</div>
        </div>
    </div>

    <div class="mt-4 grid gap-4 xl:grid-cols-3">
        {{-- Installations --}}
        <div class="card xl:col-span-2">
            <div class="flex items-center justify-between p-5 pb-2 sm:p-6 sm:pb-2">
                <div><h2 class="font-semibold text-white">Your installations</h2><p class="text-sm text-ink-400">Every domain running LabelDesk under your licenses</p></div>
                <a href="{{ route('dashboard.licenses') }}" class="text-xs text-volt-300 hover:text-white">Manage →</a>
            </div>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th>Domain</th><th>License</th><th>Version</th><th>Last check-in</th></tr></thead>
                    <tbody>
                        @foreach ($installs as $i)
                            <tr>
                                <td>
                                    <div class="flex items-center gap-3">
                                        <span class="relative flex size-2">
                                            @if ($i['online'])<span class="absolute inline-flex size-full animate-ping rounded-full bg-mint-400 opacity-50"></span>@endif
                                            <span class="relative inline-flex size-2 rounded-full {{ $i['online'] ? 'bg-mint-400' : 'bg-ink-500' }}"></span>
                                        </span>
                                        <div><div class="font-medium text-white">{{ $i['domain'] }}</div><div class="text-xs capitalize text-ink-500">{{ $i['env'] }}</div></div>
                                    </div>
                                </td>
                                <td><span class="font-mono text-xs text-ink-300">{{ $i['license'] }}</span> <span class="text-xs text-ink-500">· {{ $i['tier'] }}</span></td>
                                <td>
                                    @if ($i['version'] === $latest)
                                        <span class="badge badge-mint font-mono">v{{ $i['version'] }}</span>
                                    @else
                                        <span class="badge badge-amber font-mono">v{{ $i['version'] }} → {{ $latest }}</span>
                                    @endif
                                </td>
                                <td class="text-ink-400">{{ $i['last'] }}</td>
                            </tr>
                        @endforeach
                        @if ($installs->isEmpty())<tr><td colspan="4" class="py-10 text-center text-ink-500">No installations yet. Install {{ setting('branding.product_name') }} and activate it with your license key.</td></tr>@endif
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Latest release --}}
        @if ($release)
        <div class="relative overflow-hidden rounded-2xl border border-white/10 p-6" style="background: radial-gradient(120% 100% at 100% 0%, rgb(124 92 255 / .25), transparent 55%), radial-gradient(100% 80% at 0% 100%, rgb(255 63 210 / .14), transparent 60%), #0b0b12">
            <div class="flex items-center justify-between">
                <span class="badge badge-mint">Latest release</span>
                <span class="text-xs text-ink-400">{{ $release['date'] }}</span>
            </div>
            <div class="mt-4 text-4xl font-semibold tracking-tight text-white">LabelDesk {{ $release['version'] }}</div>
            <ul class="mt-5 space-y-2.5 text-sm">
                @foreach (array_slice($release['notes'], 0, 3) as [$kind, $note])
                    <li class="flex gap-2.5 text-ink-200"><x-icon name="check" class="mt-0.5 size-4 text-volt-300" stroke="2.25"/>{{ $note }}</li>
                @endforeach
            </ul>
            <div class="mt-6 flex gap-2">
                <a href="{{ route('dashboard.downloads') }}" class="btn btn-white btn-sm flex-1"><x-icon name="download" class="size-3.5"/> Download</a>
                <a href="{{ route('dashboard.downloads') }}#changelog" class="btn btn-outline btn-sm flex-1">Changelog</a>
            </div>
        </div>
        @endif
    </div>

    <div class="mt-4 grid gap-4 xl:grid-cols-3">
        {{-- Licenses --}}
        <div class="card card-pad">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-white">Licenses</h2>
                <a href="{{ route('dashboard.licenses') }}" class="text-xs text-volt-300 hover:text-white">View all →</a>
            </div>
            <div class="mt-4 space-y-3">
                @forelse ($licenses as $l)
                    <a href="{{ route('dashboard.licenses') }}" class="flex items-center gap-3 rounded-xl border hairline bg-white/[0.02] p-3 transition hover:border-white/10">
                        <span class="grid size-9 place-items-center rounded-lg {{ $l['tier'] === 'Business' ? 'bg-gradient-to-br from-flare-500 to-volt-500' : 'bg-gradient-to-br from-volt-500 to-sky-glow' }}"><x-icon name="key" class="size-4 text-white"/></span>
                        <div class="flex-1">
                            <div class="text-[13px] font-medium text-white">{{ $l['tier'] }} <span class="text-ink-500">· {{ $l['type'] }}</span></div>
                            <div class="font-mono text-[11px] text-ink-500">{{ $l['id'] }} · {{ $l['domains_used'] }}/{{ $l['domains'] }} domains</div>
                        </div>
                        <span class="badge {{ Demo::statusBadge($l['status']) }}">{{ $l['status'] === 'expiring' ? $l['days_left'].'d left' : ucfirst($l['status']) }}</span>
                    </a>
                @empty
                    <a href="{{ route('pricing') }}" class="block rounded-xl border border-dashed border-white/10 p-4 text-sm text-ink-400 hover:border-volt-500/50 hover:text-white">No licenses yet. Buy your first one →</a>
                @endforelse
            </div>
        </div>

        {{-- Support --}}
        <div class="card card-pad">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-white">Support tickets</h2>
                <a href="{{ route('dashboard.support') }}" class="text-xs text-volt-300 hover:text-white">All tickets →</a>
            </div>
            <div class="mt-4 space-y-1">
                @forelse (array_slice($tickets, 0, 3) as $t)
                    <a href="{{ route('dashboard.support') }}" class="block rounded-xl p-3 transition hover:bg-white/[0.03]">
                        <div class="flex items-center justify-between gap-3"><span class="font-mono text-[11px] text-ink-500">{{ $t['id'] }}</span><span class="badge {{ Demo::statusBadge($t['status']) }} !text-[10px]">{{ $t['status'] === 'awaiting' ? 'Awaiting you' : ucfirst($t['status']) }}</span></div>
                        <div class="mt-1 truncate text-sm text-ink-100">{{ $t['subject'] }}</div>
                        <div class="text-xs text-ink-500">Updated {{ $t['updated'] }}</div>
                    </a>
                @empty
                    <p class="p-3 text-sm text-ink-500">No open tickets. Need help? <a href="{{ route('dashboard.support') }}" class="text-volt-300">Contact support</a>.</p>
                @endforelse
            </div>
        </div>

        {{-- Invoices --}}
        <div class="card card-pad">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-white">Recent invoices</h2>
                <a href="{{ route('dashboard.billing') }}" class="text-xs text-volt-300 hover:text-white">Billing →</a>
            </div>
            <div class="mt-4 space-y-1">
                @forelse ($invoices as $inv)
                    <a href="{{ route('dashboard.invoices.show', $inv) }}" class="flex items-center gap-3 rounded-xl p-3 transition hover:bg-white/[0.03]">
                        <span class="grid size-8 place-items-center rounded-lg bg-white/[0.04] text-ink-400"><x-icon name="receipt" class="size-4"/></span>
                        <div class="min-w-0 flex-1"><div class="truncate text-sm text-ink-100">{{ $inv->description() }}</div><div class="text-xs text-ink-500">{{ $inv->number }} · {{ local_time($inv->issued_at)->format('M d, Y') }}</div></div>
                        <span class="font-mono text-sm text-white">{{ fmt_money($inv->total, $inv->currency) }}</span>
                    </a>
                @empty
                    <p class="p-3 text-sm text-ink-500">No invoices yet.</p>
                @endforelse
            </div>
        </div>
    </div>
</x-layouts.app>
