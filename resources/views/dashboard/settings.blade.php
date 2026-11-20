@php use App\Support\Demo; $u = auth()->user();
    $team = $u->organisation?->users->map(fn ($m) => ['name' => $m->name, 'email' => $m->email, 'role' => ucfirst($m->role), 'initials' => $m->initials(), 'last' => $m->id === $u->id ? 'You' : 'Member'])->values()->all() ?? [];
    if ($u->organisation?->billing_org_id === \App\Services\Billing\LocalBillingClient::DEMO_ORG) { $team = array_merge($team, array_slice(Demo::team(), 1)); }
@endphp
<x-layouts.app title="Settings" subtitle="Your profile, team access, notifications, security and API tokens.">
    <div class="grid gap-8 lg:grid-cols-[220px_1fr]" x-data="{ s: 'profile' }">
        <nav class="flex gap-1 overflow-x-auto lg:flex-col">
            @foreach (['profile' => ['Profile', 'users'], 'team' => ['Team', 'user-plus'], 'notifications' => ['Notifications', 'bell'], 'security' => ['Security', 'lock'], 'api' => ['API tokens', 'link']] as $k => [$l, $ic])
                <button @click="s = '{{ $k }}'" :class="s === '{{ $k }}' && 'active'" class="nav-link whitespace-nowrap"><x-icon :name="$ic"/> {{ $l }}</button>
            @endforeach
        </nav>

        <div>
            {{-- Profile --}}
            <form x-show="s === 'profile'" method="POST" action="{{ route('dashboard.settings.profile') }}" class="card card-pad space-y-6">
                @csrf
                <div class="flex items-center gap-5">
                    <span class="grid size-20 place-items-center rounded-2xl bg-gradient-to-br from-volt-500 to-flare-500 text-2xl font-semibold text-white">{{ $u->initials() }}</span>
                    <div><div class="font-medium text-white">{{ $u->name }}</div><div class="text-sm text-ink-400">{{ $u->email }} · <span class="capitalize">{{ $u->role }}</span></div></div>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><label class="label" for="p_name">Full name</label><input id="p_name" name="name" class="input" value="{{ old('name', $u->name) }}" required></div>
                    <div><label class="label" for="p_email">Email</label><input id="p_email" class="input" value="{{ $u->email }}" disabled></div>
                    <div class="sm:col-span-2">
                        <label class="label" for="p_tz">Time zone</label>
                        <select id="p_tz" name="timezone" class="input">
                            <option value="">Automatic (detected: {{ $localeTimezone }})</option>
                            @foreach (timezone_identifiers_list() as $tz)<option value="{{ $tz }}" @selected($u->timezone === $tz)>{{ str_replace('_', ' ', $tz) }}</option>@endforeach
                        </select>
                        <p class="mt-1.5 text-xs text-ink-500">Everything is stored in UTC. Dates and times are shown in this time zone. Right now it’s {{ local_time(now())->format('H:i') }} here and {{ now()->format('H:i') }} UTC.</p>
                    </div>
                </div>
                <div class="flex justify-end border-t hairline pt-5"><button class="btn btn-primary">Save changes</button></div>
            </form>

            {{-- Team --}}
            <div x-show="s === 'team'" x-cloak class="card" x-data="{ team: {{ json_encode($team) }}, email: '', role: 'Technical' }">
                <div class="border-b hairline p-5 sm:p-6">
                    <h2 class="font-semibold text-white">Team access</h2>
                    <p class="text-sm text-ink-400">Let colleagues manage licenses, download updates or pay invoices.</p>
                    <form class="mt-4 flex flex-col gap-2 sm:flex-row" @submit.prevent="if (email) { team.push({ name: email.split('@')[0], email, role, initials: email.slice(0, 2).toUpperCase(), last: 'Invited' }); toast('Invitation sent to ' + email); email = '' }">
                        <input x-model="email" type="email" class="input flex-1" placeholder="colleague@yourcompany.com">
                        <select x-model="role" class="input sm:!w-40"><option>Technical</option><option>Billing</option><option>Admin</option></select>
                        <button class="btn btn-primary">Invite</button>
                    </form>
                    <div class="mt-4 grid gap-2 text-xs text-ink-500 sm:grid-cols-3">
                        <div><span class="text-ink-200">Technical:</span> keys, domains, downloads, support</div>
                        <div><span class="text-ink-200">Billing:</span> invoices, payments, renewals</div>
                        <div><span class="text-ink-200">Admin:</span> everything except deleting the account</div>
                    </div>
                </div>
                <div class="divide-y divide-white/[0.04]">
                    <template x-for="(m, i) in team" :key="m.email">
                        <div class="flex items-center gap-3 px-5 py-3.5 sm:px-6">
                            <span class="grid size-9 place-items-center rounded-full bg-gradient-to-br from-volt-500/70 to-flare-500/70 text-xs font-semibold text-white" x-text="m.initials"></span>
                            <div class="min-w-0 flex-1"><div class="truncate text-sm font-medium text-white" x-text="m.name"></div><div class="truncate text-xs text-ink-500" x-text="m.email"></div></div>
                            <span class="hidden text-xs text-ink-500 sm:block" x-text="m.last"></span>
                            <span class="badge" :class="m.role === 'Owner' ? 'badge-volt' : 'badge-ink'" x-text="m.role"></span>
                            <button x-show="m.role !== 'Owner'" class="btn btn-ghost btn-sm !p-1.5 text-ink-500 hover:!text-coral-400" @click="team.splice(i, 1); toast('Access removed')" aria-label="Remove"><x-icon name="trash" class="size-4"/></button>
                        </div>
                    </template>
                </div>
            </div>

            {{-- Notifications --}}
            <div x-show="s === 'notifications'" x-cloak class="card divide-y divide-white/[0.05]">
                @foreach ([
                    ['License renewal reminders', '30, 14 and 3 days before a license or its updates expire', true],
                    ['New LabelDesk versions', 'When a new release is ready to download', true],
                    ['Security advisories', 'Critical patches for your installed versions (always recommended)', true],
                    ['Check-in failures', 'When an installation hasn’t contacted the license server for 72 hours', true],
                    ['Invoices & receipts', 'A copy of every invoice by email', true],
                    ['Product news', 'Roadmap updates and webinars', false],
                ] as [$t, $d, $on])
                    <div class="flex items-center justify-between gap-6 p-5">
                        <div><div class="text-sm font-medium text-white">{{ $t }}</div><div class="text-xs text-ink-400">{{ $d }}</div></div>
                        <button type="button" class="switch" x-data="{ on: {{ $on ? 'true' : 'false' }} }" :aria-checked="on.toString()" @click="on = !on" role="switch"><span></span></button>
                    </div>
                @endforeach
            </div>

            {{-- Security --}}
            <div x-show="s === 'security'" x-cloak class="space-y-4">
                <div class="card card-pad flex items-center justify-between gap-4">
                    <div class="flex items-center gap-4"><span class="grid size-10 place-items-center rounded-xl bg-mint-500/15 text-mint-400"><x-icon name="shield"/></span>
                        <div><div class="text-sm font-medium text-white">Two-factor authentication</div><div class="text-xs text-ink-400">On · Authenticator app</div></div></div>
                    <button class="btn btn-outline btn-sm">Manage</button>
                </div>
                <form class="card card-pad space-y-4" @submit.prevent="toast('Password updated')">
                    <div class="text-sm font-medium text-white">Change password</div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div><label class="label">Current password</label><input type="password" class="input" autocomplete="current-password"></div>
                        <div><label class="label">New password</label><input type="password" class="input" autocomplete="new-password"></div>
                    </div>
                    <div class="flex justify-end"><button class="btn btn-primary btn-sm">Update password</button></div>
                </form>
                <div class="card card-pad">
                    <div class="text-sm font-medium text-white">Active sessions</div>
                    <div class="mt-4 space-y-3">
                        @foreach ([['Chrome on macOS', 'Bristol, UK · current session'], ['Safari on iPhone', 'Bristol, UK · 2 days ago']] as [$d, $m])
                            <div class="flex items-center gap-3 rounded-xl border hairline p-3"><x-icon name="monitor" class="text-ink-400"/><div class="flex-1"><div class="text-sm text-white">{{ $d }}</div><div class="text-xs text-ink-500">{{ $m }}</div></div>
                                @if (! str_contains($m, 'current'))<button class="btn btn-danger btn-sm" @click="toast('Session signed out')">Sign out</button>@endif</div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- API --}}
            <div x-show="s === 'api'" x-cloak class="card card-pad" x-data="{ reveal: false }">
                <div class="text-sm font-medium text-white">Licensing API token</div>
                <p class="mt-1 text-xs text-ink-400">Automate domain activations and download updates from CI/CD or your own deployment scripts.</p>
                <div class="mt-4 flex gap-2">
                    <code class="min-w-0 flex-1 truncate rounded-xl border hairline bg-ink-950 px-3.5 py-2.5 font-mono text-[13px] text-ink-200" x-text="reveal ? 'mdk_live_demo_9f3a1c7e2b4d8f60a5e1' : 'mdk_live_••••••••••••••••••••'"></code>
                    <button class="btn btn-outline btn-sm" @click="reveal = !reveal" aria-label="Show token"><x-icon name="eye"/></button>
                    <button class="btn btn-outline btn-sm" @click="$copy('mdk_live_demo_9f3a1c7e2b4d8f60a5e1')" aria-label="Copy token"><x-icon name="copy"/></button>
                </div>
                <div class="mt-4 rounded-xl border hairline bg-ink-950 p-3 font-mono text-[12px] text-ink-300">
                    <span class="text-ink-500">$</span> curl -H "Authorization: Bearer $MDK_TOKEN" \<br>&nbsp;&nbsp;https://api.musicdesk.tech/v1/licenses/LIC-24A7/domains
                </div>
                <button class="btn btn-danger btn-sm mt-4" @click="if (confirm('Regenerate the token? Scripts using the old token will stop working.')) toast('Token regenerated')"><x-icon name="refresh" class="size-3.5"/> Regenerate token</button>
            </div>
        </div>
    </div>
</x-layouts.app>
