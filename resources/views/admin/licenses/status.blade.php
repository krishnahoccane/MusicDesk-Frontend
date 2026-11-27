@php use App\Models\LicenseAlert; use App\Models\LicenseActivation; @endphp
<x-layouts.app area="admin" title="License status" subtitle="Every domain running LabelDesk, and installs whose license check failed. Issue a license to legitimate ones; block the rest.">
    <x-slot:eyebrow><a href="{{ route('admin.licenses') }}" class="hover:text-white">Admin · Licenses</a></x-slot:eyebrow>
    <x-slot:actions>
        <button class="btn btn-outline btn-sm" @click="$dispatch('open-block')"><x-icon name="shield" class="size-3.5"/> Block a domain</button>
    </x-slot:actions>

    <div class="grid gap-4 sm:grid-cols-3 xl:grid-cols-6">
        @foreach ([['Live domains', $stats['domains'], '#7c5cff'], ['Online (26 h)', $stats['online'], '#22d9a0'], ['Silent > 7 days', $stats['silent'], '#ffb547'], ['Open alerts', $stats['open'], '#ff5757'], ['Failures, last 24 h', $stats['failures24'], '#ff3fd2'], ['Blocked domains', $stats['blocked'], '#9b98ad']] as [$label, $value, $color])
            <div class="card card-pad">
                <div class="text-[13px] text-ink-400">{{ $label }}</div>
                <div class="mt-2 text-2xl font-semibold" style="color: {{ $color }}">{{ $value }}</div>
            </div>
        @endforeach
    </div>

    <form method="GET" class="mt-4 flex flex-col gap-3 sm:flex-row">
        <div class="relative flex-1"><x-icon name="search" class="absolute left-3 top-1/2 -translate-y-1/2 text-ink-500"/>
            <input name="q" value="{{ request('q') }}" class="input !py-2 pl-9 text-[13px]" placeholder="Domain, IP, license number or customer"></div>
        <input type="hidden" name="alerts" value="{{ $state }}">
        <button class="btn btn-outline btn-sm">Search</button>
        @if (request('q'))<a href="{{ route('admin.license-status', ['alerts' => $state]) }}" class="btn btn-ghost btn-sm">Clear</a>@endif
    </form>

    {{-- Security alerts --}}
    <section class="card mt-4" id="alerts">
        <div class="flex flex-col gap-3 border-b hairline p-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="flex items-center gap-2 font-semibold text-white"><x-icon name="shield" class="size-4 text-coral-400"/> Failed license checks</h2>
                <p class="mt-0.5 text-xs text-ink-500">Reported by LabelDesk installs (security webhook) or refused by this license server. Admins are emailed for each new domain.</p>
            </div>
            <div class="flex gap-1 rounded-xl border hairline bg-white/[0.02] p-1 text-[13px]">
                @foreach (['open' => 'Open', 'blocked' => 'Blocked', 'issued' => 'Issued', 'dismissed' => 'Dismissed', 'all' => 'All'] as $k => $l)
                    <a href="{{ route('admin.license-status', ['alerts' => $k, 'q' => request('q')]) }}#alerts" class="rounded-lg px-3 py-1.5 font-medium transition {{ $state === $k ? 'bg-white/10 text-white' : 'text-ink-400 hover:text-white' }}">{{ $l }}</a>
                @endforeach
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>Domain</th><th>Why it failed</th><th>Attempts</th><th>Last seen</th><th>Status</th><th class="text-right">Action</th></tr></thead>
                <tbody>
                    @forelse ($alerts as $a)
                        @php $known = $live->get($a->domain); @endphp
                        <tr>
                            <td>
                                <div class="font-mono text-[13px] text-white">{{ $a->domain }}</div>
                                <div class="mt-0.5 text-[11px] {{ $known ? 'text-ink-400' : 'text-amber-glow' }}">
                                    @if ($known)
                                        Licensed: <a href="{{ route('admin.licenses.show', $known->license) }}" class="text-volt-300 hover:text-white">{{ $known->license->number }}</a>
                                        @if ($known->license->isAddon())<span class="badge badge-flare !text-[10px]">Add-on</span> {{ $known->license->addon?->name }}@endif
                                        · {{ $known->license->organisation->name }}
                                    @else
                                        Not on any license
                                    @endif
                                </div>
                            </td>
                            <td class="max-w-[240px]">
                                <div class="text-ink-100">{{ LicenseAlert::reasonLabel($a->last_reason) }}</div>
                                <div class="mt-0.5 text-[11px] text-ink-500">
                                    {{ $a->source === 'server' ? 'Refused by license server' : 'Reported by install' }}
                                    @if ($a->license && ! $known) · key of <a href="{{ route('admin.licenses.show', $a->license) }}" class="text-volt-300 hover:text-white">{{ $a->license->number }}</a>@if ($a->license->isAddon()) <span class="badge badge-flare !text-[10px]">Add-on</span> {{ $a->license->addon?->name }}@endif @endif
                                    @if (count($a->reasons ?? []) > 1) · {{ count($a->reasons) }} kinds of failure @endif
                                </div>
                                @if ($a->message)<div class="mt-1 truncate text-[11px] text-ink-500" title="{{ $a->message }}">“{{ $a->message }}”</div>@endif
                            </td>
                            <td class="font-mono text-ink-200">{{ number_format($a->attempts) }}</td>
                            <td class="whitespace-nowrap">
                                <div class="text-ink-300" title="First seen {{ local_time($a->first_seen_at)->format('M d, Y H:i') }}">{{ $a->last_seen_at->diffForHumans() }}</div>
                                <div class="text-[11px] text-ink-500">{{ $a->ip ?? '—' }}{{ $a->version ? ' · v'.$a->version : '' }}</div>
                            </td>
                            <td>
                                <span class="badge badge-dot {{ $a->badge() }}">{{ $a->status }}</span>
                                @if ($a->resolved_by)<div class="mt-1 text-[11px] text-ink-500" title="{{ $a->note }}">by {{ $a->resolved_by }}</div>@endif
                            </td>
                            <td class="text-right">
                                <div class="flex justify-end gap-1.5" x-data>
                                    @include('admin.partials.remote-login-button', ['domain' => $a->domain, 'license' => $known?->license ?? $a->license, 'compact' => true])
                                    @if ($a->status === LicenseAlert::BLOCKED)
                                        <form method="POST" action="{{ route('admin.licenses.alerts.update', $a) }}" onsubmit="return confirm('Unblock {{ $a->domain }}? It will be able to activate again with a valid key.')">@csrf @method('PUT')<input type="hidden" name="status" value="dismissed"><button class="btn btn-outline btn-sm">Unblock</button></form>
                                    @else
                                        @if (! $known)
                                            <a href="{{ route('admin.licenses.create', ['domain' => $a->domain, 'alert' => $a->id, 'organisation' => $a->license?->organisation_id]) }}" class="btn btn-primary btn-sm"><x-icon name="key" class="size-3.5"/> Issue license</a>
                                        @else
                                            <a href="{{ route('admin.licenses.show', $known->license) }}" class="btn btn-outline btn-sm">Open license</a>
                                        @endif
                                        <button type="button" class="btn btn-danger btn-sm" @click="$dispatch('open-block-confirm', { id: {{ $a->id }}, domain: @js($a->domain), known: {{ $known ? 'true' : 'false' }} })">Block</button>
                                        @if ($a->status === LicenseAlert::OPEN)
                                            <form method="POST" action="{{ route('admin.licenses.alerts.update', $a) }}">@csrf @method('PUT')<input type="hidden" name="status" value="dismissed"><button class="btn btn-ghost btn-sm !px-2" title="Dismiss. It reopens if the domain fails again." aria-label="Dismiss alert for {{ $a->domain }}"><x-icon name="x" class="size-3.5"/></button></form>
                                        @endif
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-12 text-center text-ink-500">
                            <x-icon name="shield" class="mx-auto mb-2 size-5 text-mint-400"/>
                            {{ $state === 'open' ? 'No open alerts. Every install that checked in passed its license check.' : 'Nothing here.' }}
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($alerts->hasPages())<div class="border-t hairline px-4 py-3 text-sm">{{ $alerts->fragment('alerts')->links() }}</div>@endif
    </section>

    {{-- Live domains --}}
    <section class="card mt-4" id="domains">
        <div class="flex flex-col gap-3 border-b hairline p-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="font-semibold text-white">Active domains</h2>
                <p class="mt-0.5 text-xs text-ink-500">Every domain on a license that hasn’t been deactivated. Installs check in every 24 hours.</p>
            </div>
            <div class="flex gap-1 rounded-xl border hairline bg-white/[0.02] p-1 text-[13px]">
                @foreach (['' => 'All', 'production' => 'Production', 'staging' => 'Staging'] as $k => $l)
                    <a href="{{ route('admin.license-status', array_filter(['alerts' => $state, 'q' => request('q'), 'env' => $k, 'online' => request('online')])) }}#domains" class="rounded-lg px-3 py-1.5 font-medium transition {{ (string) request('env') === $k ? 'bg-white/10 text-white' : 'text-ink-400 hover:text-white' }}">{{ $l }}</a>
                @endforeach
                <a href="{{ route('admin.license-status', array_filter(['alerts' => $state, 'q' => request('q'), 'env' => request('env'), 'online' => request('online') ? null : '1'])) }}#domains" class="rounded-lg px-3 py-1.5 font-medium transition {{ request('online') ? 'bg-white/10 text-white' : 'text-ink-400 hover:text-white' }}">Online only</a>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>Domain</th><th>License</th><th>Customer</th><th>Version</th><th>Last check-in</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse ($domains as $d)
                        @php
                            $online = $d->isOnline();
                            [$label, $badge] = match (true) {
                                $d->status === LicenseActivation::PENDING => ['Not installed yet', 'badge-ink'],
                                $online => ['Online', 'badge-mint'],
                                default => ['Silent', 'badge-amber'],
                            };
                        @endphp
                        <tr>
                            <td>
                                <div class="font-mono text-[13px] text-white">{{ $d->domain }}</div>
                                <div class="text-[11px] text-ink-500">{{ ucfirst($d->environment) }}{{ $d->maskedIp() ? ' · '.$d->maskedIp() : '' }}</div>
                            </td>
                            <td><div class="flex flex-wrap items-center gap-1.5"><a href="{{ route('admin.licenses.show', $d->license) }}" class="font-mono text-xs text-white hover:text-volt-300">{{ $d->license->number }}</a>
                                    @if ($d->license->isAddon())<span class="badge badge-flare !text-[10px]">Add-on</span><span class="text-xs text-ink-200">{{ $d->license->addon?->name }}</span>@endif</div>
                                <div class="text-[11px] text-ink-500">{{ $d->license->plan->name }} · <span class="{{ in_array($d->license->displayStatus(), ['suspended', 'revoked', 'expired'], true) ? 'text-coral-400' : '' }}">{{ $d->license->displayStatus() }}</span></div></td>
                            <td><a href="{{ route('admin.customers.show', $d->license->organisation) }}" class="text-ink-100 hover:text-white">{{ $d->license->organisation->name }}</a></td>
                            <td class="font-mono text-ink-300">{{ $d->version ? 'v'.$d->version : '—' }}</td>
                            <td class="whitespace-nowrap text-ink-300">{{ $d->last_check_in_at?->diffForHumans() ?? 'Never' }}</td>
                            <td><span class="badge badge-dot {{ $badge }}">{{ $label }}</span></td>
                            <td class="text-right">@include('admin.partials.remote-login-button', ['domain' => $d->domain, 'license' => $d->license, 'compact' => true])</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-12 text-center text-ink-500">No domains match.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($domains->hasPages())<div class="border-t hairline px-4 py-3 text-sm">{{ $domains->fragment('domains')->links() }}</div>@endif
    </section>

    {{-- Block an alert's domain --}}
    <div>
        <x-modal event="open-block-confirm" title="Block this domain?" width="max-w-lg">
            <form method="POST" :action="@js(url('admin/licenses/status')) + '/' + data.id + '/block'" class="mt-4 space-y-4">
                @csrf
                <p class="text-sm text-ink-300"><b class="font-mono text-white" x-text="data.domain"></b> won’t be able to activate or check in with any key. LabelDesk installs that report from it are told to lock.</p>
                <p x-show="data.known" class="rounded-xl border border-coral-500/25 bg-coral-500/[0.06] p-3 text-xs text-coral-400">This domain is on a customer’s license. Blocking also deactivates it there.</p>
                <div><label class="label" for="block-note">Note <span class="text-ink-500">(optional, admins only)</span></label><input id="block-note" name="note" class="input" maxlength="500" placeholder="e.g. Nulled copy from a forum"></div>
                <div class="flex justify-end gap-2"><button type="button" class="btn btn-ghost" @click="open = false">Cancel</button><button class="btn btn-danger">Block domain</button></div>
            </form>
        </x-modal>
    </div>

    {{-- Block any domain --}}
    <x-modal event="open-block" title="Block a domain" subtitle="Stops a domain from activating LabelDesk, even before it reports anything." width="max-w-lg">
        <form method="POST" action="{{ route('admin.licenses.alerts.block-domain') }}" class="mt-4 space-y-4">
            @csrf
            <div><label class="label" for="block-domain">Domain</label><input id="block-domain" name="domain" class="input" required placeholder="nulled-labeldesk.example" value="{{ old('domain') }}">@error('domain')<p class="mt-1 text-xs text-coral-400">{{ $message }}</p>@enderror</div>
            <div><label class="label" for="block-domain-note">Note <span class="text-ink-500">(optional)</span></label><input id="block-domain-note" name="note" class="input" maxlength="500"></div>
            <div class="flex justify-end gap-2"><button type="button" class="btn btn-ghost" @click="open = false">Cancel</button><button class="btn btn-danger">Block domain</button></div>
        </form>
    </x-modal>
</x-layouts.app>
