@php
    use App\Support\Demo;
    $latest = \App\Models\Release::latestVersion();
    $domainsUsed = array_sum(array_column($licenses, 'domains_used'));
    $domainsTotal = array_sum(array_column($licenses, 'domains'));
    $priceOf = fn ($l, $factor = 1) => isset($l['price_currency']) ? fmt_money(round($l['price'] * $factor), $l['price_currency']) : money(round($l['price'] * $factor))['display'];
    $expiring = collect($licenses)->where('status', 'expiring')->sortBy('days_left')->first();
    $covered = collect($licenses)->reject(fn ($l) => $l['support_expired'] ?? false)->count();
    $lapsed = count($licenses) - $covered;
@endphp
<x-layouts.app title="Licenses" subtitle="License keys, activated domains, renewals and upgrades for LabelDesk.">
    <x-slot:eyebrow>Portal · Licensing</x-slot:eyebrow>
    <x-slot:actions>
        <a href="#" @click.prevent="$dispatch('open-offline', {})" class="btn btn-outline btn-sm"><x-icon name="file" class="size-3.5"/> Offline activation</a>
        <button class="btn btn-primary btn-sm" @click="$dispatch('open-buy')"><x-icon name="plus" class="size-3.5"/> Buy license</button>
    </x-slot:actions>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat label="Licenses" value="{{ count($licenses) }}" icon="key" color="#ff3fd2"/>
        <div class="card card-pad">
            <div class="flex items-center justify-between text-[13px] text-ink-400">Production domains <x-icon name="globe" class="size-4 text-volt-300"/></div>
            <div class="mt-3 text-[28px] font-semibold leading-none tracking-tight text-white">{{ $domainsUsed }}<span class="text-lg text-ink-500"> / {{ $domainsTotal }}</span></div>
            <div class="mt-4 h-1.5 overflow-hidden rounded-full bg-white/[0.06]"><div class="h-full rounded-full bg-gradient-to-r from-volt-500 to-flare-500" style="width: {{ $domainsTotal ? round($domainsUsed / $domainsTotal * 100) : 0 }}%"></div></div>
        </div>
        <div class="card card-pad">
            <div class="flex items-center justify-between text-[13px] text-ink-400">Updates & support <x-icon name="shield" class="size-4 text-mint-400"/></div>
            <div class="mt-3 text-[28px] font-semibold leading-none tracking-tight text-white">{{ $covered }}<span class="text-lg text-ink-500"> / {{ count($licenses) }}</span></div>
            <div class="mt-2.5 text-xs text-ink-500">{{ $lapsed ? $lapsed.' license(s) out of the update period' : 'All licenses get updates' }}</div>
        </div>
        @if ($expiring)
        <div class="relative overflow-hidden rounded-2xl border border-amber-glow/25 bg-amber-glow/[0.06] p-5 sm:p-6">
            <div class="flex items-center gap-2 text-[13px] text-amber-glow"><x-icon name="alert" class="size-4"/> Renewal due</div>
            <div class="mt-3 text-[15px] font-medium text-white">{{ $expiring['id'] }} expires in {{ $expiring['days_left'] }} days</div>
            <a href="{{ route('licenses.buy', [$expiring['uid'], 'renewal']) }}" class="btn btn-sm mt-3 bg-amber-glow text-ink-950 hover:brightness-110">Renew now</a>
        </div>
        @else
        <a href="{{ route('pricing') }}" class="card card-pad group">
            <div class="flex items-center justify-between text-[13px] text-ink-400">Need more domains? <x-icon name="plus" class="size-4 text-volt-300"/></div>
            <div class="mt-3 text-[15px] font-medium text-white group-hover:text-volt-300">Add domains or another license →</div>
        </a>
        @endif
    </div>

    <div class="mt-6 space-y-4">
        @if (empty($licenses))
            <div class="card grid place-items-center px-6 py-14 text-center">
                <span class="grid size-14 place-items-center rounded-2xl bg-volt-500/15 text-volt-300"><x-icon name="key" class="size-6"/></span>
                <div class="mt-4 font-medium text-white">No licenses yet</div>
                <p class="mt-1 max-w-sm text-sm text-ink-400">Buy a {{ setting('branding.product_name') }} license and your key, domains and renewals will show up here.</p>
            </div>
        @endif
        @foreach ($licenses as $idx => $l)
            @php $supportExpired = $l['support_expired'] ?? false; @endphp
            <div class="card overflow-hidden" x-data="{ open: {{ $idx === 0 ? 'true' : 'false' }}, tab: 'domains', reveal: false, key: null, busy: false,
                    installs: {{ json_encode($l['installations']) }}, max: {{ max(1, $l['domains']) }},
                    base: @js(url('/dashboard/licenses/'.$l['uid'])),
                    async fetchKey() { if (!this.key) { this.key = (await portalApi(this.base + '/reveal')).key } return this.key },
                    async toggleReveal() { if (!this.reveal) { await this.fetchKey() } this.reveal = !this.reveal },
                    async copyKey() { this.$copy(await this.fetchKey()) },
                    async deactivate(d, i) { if (!confirm('Deactivate ' + d.domain + '? LabelDesk on that domain switches to read-only until it is activated again.')) return; const r = await portalApi(this.base + '/domains/' + encodeURIComponent(d.id || d.domain), 'DELETE'); this.installs.splice(i, 1); toast(r.message) },
                    async checkIn(d) { const r = await portalApi(this.base + '/domains/' + encodeURIComponent(d.id || d.domain) + '/check-in'); toast(r.message) },
                    async autoRenew(on) { const r = await portalApi(this.base + '/auto-renew', 'POST', { auto_renew: on }); toast(r.message); setTimeout(() => location.reload(), 600) },
                 }">
                <div class="flex flex-col gap-5 p-5 sm:p-6 lg:flex-row lg:items-center">
                    <div class="flex flex-1 items-center gap-4">
                        <span class="relative grid size-12 shrink-0 place-items-center rounded-2xl {{ $l['tier'] === 'Business' ? 'bg-gradient-to-br from-flare-500 to-volt-500' : 'bg-gradient-to-br from-volt-500 to-sky-glow' }}">
                            <x-icon name="key" class="size-5 text-white"/>
                            @if ($l['status'] === 'expiring' || $supportExpired)<span class="absolute -right-1 -top-1 size-3 rounded-full bg-amber-glow ring-2 ring-ink-900"></span>@endif
                        </span>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="font-semibold text-white">{{ $l['product'] ?? 'LabelDesk' }} {{ $l['tier'] }}</h3>
                                <span class="badge badge-ink">{{ $l['type'] }}</span>
                                <span class="badge badge-dot {{ Demo::statusBadge($l['status']) }}">{{ $l['status'] === 'expiring' ? 'Expires in '.$l['days_left'].' days' : ucfirst($l['status']) }}</span>
                                @if ($supportExpired)<span class="badge badge-amber">Updates expired</span>@endif
                            </div>
                            <div class="mt-1.5 flex items-center gap-2">
                                @php $masked = ($l['key_masked'] ?? false) ? $l['key'] : ($l['key'] ? substr($l['key'], 0, 5).'••••-••••-••••-'.substr($l['key'], -4) : 'Key being generated…'); @endphp
                                <code class="font-mono text-[13px] tracking-wider text-ink-300" x-text="reveal && key ? key : @js($masked)">{{ $masked }}</code>
                                @if (true)
                                <button @click="toggleReveal()" class="text-ink-500 hover:text-white" :aria-label="reveal ? 'Hide key' : 'Reveal key'" title="Reveal key (recorded in the license history)"><x-icon name="eye" class="size-3.5"/></button>
                                <button @click="copyKey()" class="text-ink-500 hover:text-white" aria-label="Copy key"><x-icon name="copy" class="size-3.5"/></button>
                                @endif
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-6 lg:w-[440px]">
                        <div>
                            <div class="text-[11px] text-ink-500">Domains</div>
                            <div class="mt-1 text-sm font-medium text-white"><span x-text="installs.filter(i => i.env === 'production').length"></span> / {{ $l['domains'] }}</div>
                            <div class="mt-2 h-1 overflow-hidden rounded-full bg-white/[0.06]"><div class="h-full rounded-full bg-volt-500 transition-all" :style="`width: ${installs.filter(i => i.env === 'production').length / max * 100}%`"></div></div>
                        </div>
                        <div>
                            <div class="text-[11px] text-ink-500">{{ $l['renews'] ? 'Renews' : 'Updates until' }}</div>
                            <div class="mt-1 text-sm font-medium {{ $l['status'] === 'expiring' || $supportExpired ? 'text-amber-glow' : 'text-white' }}">{{ $l['renews'] ?? $l['support_until'] }}</div>
                            <div class="mt-2 text-[11px] text-ink-500">{{ $l['renews'] ? ($l['auto_renew'] ? 'Reminders on' : 'Reminders off') : 'Lifetime license' }}</div>
                        </div>
                        <div>
                            <div class="text-[11px] text-ink-500">Paid</div>
                            <div class="mt-1 text-sm font-medium text-white">{{ $priceOf($l) }}</div>
                            <div class="mt-2 text-[11px] text-ink-500">since {{ $l['purchased'] }}</div>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <div x-data="{ m: false }" class="relative">
                            <button @click="m = !m" class="btn btn-outline btn-sm !px-2" aria-label="License actions"><x-icon name="more"/></button>
                            <div x-show="m" x-cloak @click.outside="m = false" x-transition.origin.top.right class="absolute right-0 z-20 mt-2 w-60 rounded-2xl glass !bg-ink-900/95 p-1.5 shadow-2xl shadow-black/60">
                                @if ($l['type'] === 'Lifetime')
                                    <a href="{{ route('licenses.buy', [$l['uid'], 'updates_renewal']) }}" class="nav-link w-full"><x-icon name="refresh"/> Renew updates (40% off)</a>
                                @else
                                    <a href="{{ route('licenses.buy', [$l['uid'], 'renewal']) }}" class="nav-link w-full"><x-icon name="refresh"/> Renew now</a>
                                    <a href="{{ route('licenses.buy', [$l['uid'], 'convert_lifetime']) }}" class="nav-link w-full"><x-icon name="zap"/> Switch to lifetime</a>
                                @endif
                                <a href="{{ route('licenses.buy', [$l['uid'], 'change_plan']) }}" class="nav-link w-full"><x-icon name="layers"/> Upgrade tier</a>
                                <a href="{{ route('licenses.buy', [$l['uid'], 'extra_domains']) }}" class="nav-link w-full"><x-icon name="plus"/> Buy extra domains</a>
                                @if (! empty($l['available_addons']))<a href="{{ route('licenses.buy', [$l['uid'], 'addon']) }}" class="nav-link w-full"><x-icon name="layers"/> Buy an add-on</a>@endif
                                <a href="{{ route('dashboard.licenses.certificate', $l['uid']) }}" class="nav-link w-full"><x-icon name="file"/> License certificate (PDF)</a>
                                <button class="nav-link w-full" @click="m = false; $dispatch('open-offline', { uid: @js($l['uid']), id: @js($l['id']) })"><x-icon name="upload"/> Offline activation</button>
                                @if ($l['renews'] && ! $l['auto_renew'])
                                    <button class="nav-link w-full" @click="m = false; autoRenew(true)"><x-icon name="refresh"/> Turn on renewal reminders</button>
                                @endif
                                @if ($l['renews'] && $l['auto_renew'])
                                    <div class="my-1 border-t hairline"></div>
                                    <button class="nav-link w-full !text-coral-400" @click="m = false; autoRenew(false)"><x-icon name="ban"/> Turn off renewal reminders</button>
                                @endif
                            </div>
                        </div>
                        <button @click="open = !open" class="btn btn-outline btn-sm">
                            <span x-text="open ? 'Hide' : 'Manage'"></span>
                            <x-icon name="chevron-down" class="size-3.5 transition" ::class="open && 'rotate-180'"/>
                        </button>
                    </div>
                </div>

                @if (in_array($l['status'], ['suspended', 'revoked'], true))
                    <div class="mx-5 mb-5 flex items-start gap-3 rounded-xl border border-coral-500/25 bg-coral-500/[0.06] p-4 text-sm sm:mx-6">
                        <x-icon name="ban" class="mt-0.5 size-4 text-coral-400"/>
                        <span class="flex-1 text-ink-200">This license is <b class="text-coral-400">{{ $l['status'] }}</b>{{ !empty($l['status_reason']) ? ': '.$l['status_reason'] : '' }}. Sites using it are read-only. <a href="{{ route('dashboard.support') }}" class="text-volt-300 underline">Contact support</a>.</span>
                    </div>
                @elseif ($l['status'] === 'pending')
                    <div class="mx-5 mb-5 flex items-start gap-3 rounded-xl border border-amber-glow/25 bg-amber-glow/[0.06] p-4 text-sm sm:mx-6">
                        <x-icon name="receipt" class="mt-0.5 size-4 text-amber-glow"/>
                        <span class="flex-1 text-ink-200">Awaiting your bank transfer. The license works until {{ $l['pending_until'] ?? 'the due date' }} and activates fully once payment arrives.</span>
                        <a href="{{ route('dashboard.billing') }}" class="btn btn-sm shrink-0 bg-amber-glow text-ink-950">View invoice</a>
                    </div>
                @endif
                @if ($supportExpired)
                    <div class="mx-5 mb-5 flex flex-col gap-3 rounded-xl border border-amber-glow/20 bg-amber-glow/[0.05] p-4 text-sm sm:mx-6 sm:flex-row sm:items-center">
                        <x-icon name="alert" class="size-4 text-amber-glow"/>
                        <span class="flex-1 text-ink-200">This lifetime license still works, but it can’t install versions released after {{ $l['support_until'] }}. The latest version is v{{ $latest }}.</span>
                        <a href="{{ route('licenses.buy', [$l['uid'], 'updates_renewal']) }}" class="btn btn-sm shrink-0 bg-amber-glow text-ink-950 hover:brightness-110">Renew updates (40% off)</a>
                    </div>
                @endif

                <div x-show="open" x-collapse x-cloak>
                    <div class="border-t hairline bg-ink-950/40">
                        <div class="flex gap-1 overflow-x-auto overflow-y-hidden border-b hairline px-5 sm:px-6">
                            @foreach (['domains' => 'Domains & installations', 'addons' => 'Add-ons'.(count($l['addons'] ?? []) ? ' ('.count($l['addons']).')' : ''), 'details' => 'Details & history'] as $k => $lab)
                                <button @click="tab = '{{ $k }}'" class="relative whitespace-nowrap px-3 py-3.5 text-[13px] font-medium transition" :class="tab === '{{ $k }}' ? 'text-white' : 'text-ink-400 hover:text-white'">
                                    {{ $lab }}
                                    <span x-show="tab === '{{ $k }}'" class="absolute inset-x-2 -bottom-px h-0.5 rounded-full bg-gradient-to-r from-volt-400 to-flare-400"></span>
                                </button>
                            @endforeach
                        </div>

                        {{-- Domains --}}
                        <div x-show="tab === 'domains'">
                            <div class="flex flex-wrap items-center justify-between gap-3 px-5 pt-5 sm:px-6">
                                <div class="max-w-2xl space-y-1.5 text-sm text-ink-400">
                                    <p>Domains activate automatically the first time LabelDesk checks in with your key. Staging and dev domains are free.</p>
                                    <p class="text-xs text-ink-500"><x-icon name="shield" class="mr-1 inline size-3.5 text-amber-glow"/>Deactivating LabelDesk on a domain needs a code we email to this account’s owner. If you did not ask for it, someone with administrator access to that site did — check who has access.</p>
                                </div>
                                <button class="btn btn-primary btn-sm" @click="$dispatch('open-domain', { id: '{{ $l['id'] }}', base: base })" :disabled="!base"><x-icon name="plus" class="size-3.5"/> Add domain</button>
                            </div>
                            <div class="mt-4 overflow-x-auto">
                                <table class="table">
                                    <thead><tr><th>Domain</th><th>Environment</th><th>Version</th><th>Server</th><th>Last check-in</th><th></th></tr></thead>
                                    <tbody>
                                        <template x-for="(d, i) in installs" :key="d.domain">
                                            <tr>
                                                <td>
                                                    <div class="flex items-center gap-3">
                                                        <span class="size-2 rounded-full" :class="d.online ? 'bg-mint-400' : 'bg-ink-500'"></span>
                                                        <a :href="'https://' + d.domain" target="_blank" rel="noopener" class="font-medium text-white hover:text-volt-300" x-text="d.domain"></a>
                                                    </div>
                                                </td>
                                                <td><span class="badge" :class="d.env === 'production' ? 'badge-volt' : 'badge-ink'" x-text="d.env"></span></td>
                                                <td><span class="badge font-mono" :class="d.version === '{{ $latest }}' ? 'badge-mint' : 'badge-amber'" x-text="'v' + d.version"></span></td>
                                                <td class="font-mono text-xs text-ink-500"><span x-text="d.ip"></span> · PHP <span x-text="d.php"></span></td>
                                                <td class="text-ink-400" x-text="d.last"></td>
                                                <td class="text-right">
                                                    <div x-data="{ m: false }" class="relative inline-block text-left">
                                                        <button @click="m = !m" class="btn btn-ghost btn-sm !p-1.5"><x-icon name="more"/></button>
                                                        <div x-show="m" x-cloak @click.outside="m = false" class="absolute right-0 z-20 mt-1 w-52 rounded-xl glass !bg-ink-900/95 p-1 shadow-xl">
                                                            <button class="nav-link w-full !py-1.5 text-xs" @click="m = false; $dispatch('open-domain', { id: '{{ $l['id'] }}', base: base, move: d.domain, act: d.id || d.domain })"><x-icon name="refresh" class="size-3.5"/> Move to another domain</button>
                                                            <button class="nav-link w-full !py-1.5 text-xs" @click="m = false; checkIn(d)"><x-icon name="zap" class="size-3.5"/> Force check-in</button>
                                                            <button class="nav-link w-full !py-1.5 text-xs !text-coral-400" @click="m = false; deactivate(d, i)"><x-icon name="ban" class="size-3.5"/> Deactivate</button>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        </template>
                                        <tr x-show="installs.length === 0"><td colspan="6" class="py-10 text-center text-ink-500">No domains activated yet. Install LabelDesk and enter your key to activate it.</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        {{-- Add-ons: each has its own key --}}
                        <div x-show="tab === 'addons'" x-cloak class="space-y-4 p-5 sm:p-6">
                            <p class="text-sm text-ink-400">If you change {{ setting('branding.product_name', 'LabelDesk') }} on a domain to a key from a different license, these add-on keys stop working there at the next hourly check-in, because add-ons only run on their main license’s domains.</p>
                            <p class="text-sm text-ink-400">Each add-on has its own key. Enter it in {{ setting('branding.product_name', 'LabelDesk') }} under Settings › Add-ons on any domain of {{ $l['id'] }}; it activates on that domain automatically.</p>
                            @foreach ($l['addons'] ?? [] as $a)
                                <div class="rounded-2xl border hairline bg-white/[0.02] p-4"
                                     x-data="{ reveal: false, key: null,
                                        async fetchKey() { if (!this.key) { this.key = (await portalApi(@js(url('/dashboard/licenses/'.$a['uid'].'/reveal')))).key } return this.key },
                                        async toggleReveal() { if (!this.reveal) { await this.fetchKey() } this.reveal = !this.reveal },
                                        async copyKey() { this.$copy(await this.fetchKey()) } }">
                                    <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
                                        <div class="min-w-0 flex-1">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="font-medium text-white">{{ $a['name'] }}</span>
                                                <span class="badge {{ $a['included'] ? 'badge-mint' : 'badge-ink' }}">{{ $a['included'] ? 'Included with '.$l['tier'] : $a['type'] }}</span>
                                                <span class="badge badge-dot {{ Demo::statusBadge($a['status']) }}">{{ ucfirst($a['status']) }}</span>
                                            </div>
                                            <div class="mt-1.5 flex items-center gap-2">
                                                <code class="font-mono text-[13px] tracking-wider text-ink-300" x-text="reveal && key ? key : @js($a['key'])">{{ $a['key'] }}</code>
                                                <button @click="toggleReveal()" class="text-ink-500 hover:text-white" :aria-label="reveal ? 'Hide key' : 'Reveal key'" title="Reveal key (recorded in the license history)"><x-icon name="eye" class="size-3.5"/></button>
                                                <button @click="copyKey()" class="text-ink-500 hover:text-white" aria-label="Copy {{ $a['name'] }} key"><x-icon name="copy" class="size-3.5"/></button>
                                            </div>
                                            @if ($a['status_reason'])<div class="mt-1 text-xs text-coral-400">{{ $a['status_reason'] }}</div>@endif
                                        </div>
                                        <div class="text-xs text-ink-400 lg:w-48">
                                            @if ($a['included'])Renews with {{ $l['id'] }}@elseif ($a['renews'])Renews {{ $a['renews'] }}@else Updates until {{ $a['support_until'] }}@endif
                                        </div>
                                        <div class="flex gap-2">
                                            @if (! $a['included'] && $a['type'] === 'Annual' && ! in_array($a['status'], ['suspended', 'revoked'], true))
                                                <a href="{{ route('licenses.buy', [$a['uid'], 'renewal']) }}" class="btn btn-outline btn-sm">Renew</a>
                                            @elseif (! $a['included'] && $a['support_expired'])
                                                <a href="{{ route('licenses.buy', [$a['uid'], 'updates_renewal']) }}" class="btn btn-outline btn-sm">Renew updates</a>
                                            @endif
                                        </div>
                                    </div>
                                    <div class="mt-3 flex flex-wrap gap-1.5 border-t hairline pt-3">
                                        <span class="mr-1 text-[11px] text-ink-500">Active on</span>
                                        @forelse ($a['domains'] as $d)
                                            <span class="inline-flex items-center gap-1.5 rounded-lg bg-white/[0.04] px-2 py-0.5 font-mono text-[11px] text-ink-200"><span class="size-1.5 rounded-full {{ $d['online'] ? 'bg-mint-400' : 'bg-ink-500' }}"></span>{{ $d['domain'] }}</span>
                                        @empty
                                            <span class="text-[11px] text-ink-500">No domains yet. It activates when {{ setting('branding.product_name', 'LabelDesk') }} first checks in with this key.</span>
                                        @endforelse
                                    </div>
                                </div>
                            @endforeach

                            @if (! empty($l['available_addons']))
                                <div>
                                    <div class="eyebrow mb-2 mt-2">Available add-ons</div>
                                    <div class="grid gap-3 md:grid-cols-2">
                                        @foreach ($l['available_addons'] as $x)
                                            <div class="flex items-start gap-3 rounded-2xl border border-dashed border-white/10 p-4">
                                                <x-icon name="layers" class="mt-0.5 size-4 shrink-0 text-volt-300"/>
                                                <div class="min-w-0 flex-1">
                                                    <div class="font-medium text-white">{{ $x['name'] }}</div>
                                                    @if ($x['description'])<p class="mt-0.5 text-xs text-ink-400">{{ $x['description'] }}</p>@endif
                                                    <div class="mt-1.5 text-xs text-ink-300">{{ $x['annual'] }} / year{{ $x['lifetime'] ? ' · '.$x['lifetime'].' lifetime' : '' }}</div>
                                                </div>
                                                <a href="{{ route('licenses.buy', [$l['uid'], 'addon', 'addon' => $x['code']]) }}" class="btn btn-primary btn-sm shrink-0">Add</a>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @elseif (empty($l['addons']))
                                <p class="py-6 text-center text-sm text-ink-500">No add-ons available for this license.</p>
                            @endif
                        </div>

                        {{-- Details --}}
                        <div x-show="tab === 'details'" x-cloak class="grid gap-6 p-5 sm:p-6 lg:grid-cols-2">
                            <dl class="grid grid-cols-2 gap-x-6 gap-y-4 text-sm">
                                @foreach ([
                                    'License ID' => $l['id'], 'Product' => $l['product'].' '.$l['tier'],
                                    'License type' => $l['type'], 'Purchased' => $l['purchased'],
                                    'Price paid' => $priceOf($l), 'Updates & support until' => $l['support_until'],
                                    'Renewal reminders' => $l['renews'] ? ($l['auto_renew'] ? 'On' : 'Off') : 'Not applicable', 'Licensed to' => auth()->user()->organisation?->name,
                                ] as $k => $v)
                                    <div><dt class="text-xs text-ink-500">{{ $k }}</dt><dd class="mt-0.5 text-ink-100">{{ $v }}</dd></div>
                                @endforeach
                            </dl>
                            <div>
                                <div class="eyebrow mb-3">History</div>
                                <ol class="relative space-y-4 border-l border-white/[0.08] pl-5 text-sm">
                                    @forelse ($l['activity'] ?? [] as $ev)
                                        <li class="relative">
                                            <span class="absolute -left-[25px] top-1 size-2.5 rounded-full ring-4 ring-ink-950" style="background: {{ $ev['color'] }}"></span>
                                            <div class="text-ink-100">{{ $ev['label'] }}</div><div class="text-xs text-ink-500">{{ $ev['meta'] }}</div>
                                        </li>
                                    @empty
                                        <li class="text-ink-500">No activity yet.</li>
                                    @endforelse
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Buy another --}}
    <div class="mt-10">
        <div class="flex items-end justify-between">
            <div><h2 class="text-lg font-semibold text-white">Need another license?</h2><p class="text-sm text-ink-400">For a new brand, a client platform or a separate company.</p></div>
            <a href="{{ route('pricing') }}" class="hidden text-sm text-volt-300 hover:text-white sm:block">Compare licenses →</a>
        </div>
        <div class="mt-5 grid gap-4 md:grid-cols-3">
            @foreach ($tiers as $t)
                <div class="card card-pad flex flex-col {{ ($t['featured'] ?? false) ? 'glow-border' : '' }}">
                    <div class="flex items-center justify-between"><span class="font-semibold text-white">{{ $t['name'] }}</span><span class="font-mono text-xs text-ink-400">{{ $t['domains'] }} {{ Str::plural('domain', $t['domains']) }}</span></div>
                    <div class="mt-3"><span class="text-3xl font-semibold tracking-tight text-white">{{ money($t->price_annual)['display'] }}</span><span class="text-sm text-ink-400"> / year</span></div>
                    <p class="mt-2 flex-1 text-sm text-ink-400">{{ $t['blurb'] }}</p>
                    <a href="{{ route('checkout', $t->code) }}" class="btn {{ ($t['featured'] ?? false) ? 'btn-primary' : 'btn-outline' }} btn-sm mt-5">Buy {{ $t['name'] }}</a>
                </div>
            @endforeach
        </div>
    </div>

    @include('dashboard.partials.license-modals', ['tiers' => $tiers, 'licenses' => $licenses])
</x-layouts.app>
