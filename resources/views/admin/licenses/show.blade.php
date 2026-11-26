@php
    use App\Support\Demo;
    $st = $license->displayStatus();
    $org = $license->organisation;
    $statusBadge = ['paid' => 'badge-mint', 'awaiting_payment' => 'badge-amber', 'pending' => 'badge-ink', 'failed' => 'badge-coral', 'cancelled' => 'badge-ink', 'refunded' => 'badge-sky'];
@endphp
<x-layouts.app area="admin" :title="$license->number" :subtitle="$org->name.' · '.($license->isAddon() ? $license->addon->name.' add-on'.($license->included ? ' (included with '.$license->plan->name.')' : ' '.ucfirst($license->billing_type)) : $license->plan->name.' '.ucfirst($license->billing_type))">
    <x-slot:eyebrow><a href="{{ route('admin.licenses') }}" class="hover:text-white">Admin · Licenses</a></x-slot:eyebrow>
    <x-slot:actions>
        <span class="badge badge-dot {{ Demo::statusBadge($st) }} !text-xs">{{ $st }}</span>
        <a href="{{ route('admin.customers.show', $org) }}" class="btn btn-outline btn-sm"><x-icon name="users" class="size-3.5"/> Customer</a>
        @if (auth()->user()->canAdmin('delete'))
            <button class="btn btn-danger btn-sm" @click="$dispatch('delete-license')"><x-icon name="trash" class="size-3.5"/> Delete</button>
        @endif
    </x-slot:actions>
    @if (auth()->user()->canAdmin('delete'))
        @include('admin.partials.delete-modal', ['event' => 'delete-license', 'title' => 'Delete license '.$license->number, 'action' => route('admin.licenses.destroy', $license), 'expected' => $license->number,
            'warning' => 'The key stops working: every install using it fails its next check-in and locks. Its domains'.($license->addonLicenses()->exists() ? ', add-on keys ('.$license->addonLicenses()->pluck('number')->implode(', ').')' : '').' and history are deleted. Orders and invoices are kept. To stop a license but keep its record, suspend or revoke it instead.'])
    @endif

    @if (session('new_key'))
        <div class="mb-4 rounded-2xl border border-mint-500/30 bg-mint-500/[0.07] p-5" x-data="{ key: @js(session('new_key')) }">
            <div class="text-sm font-medium text-mint-400">License key: copy it now and send it to the customer</div>
            <div class="mt-2 flex items-center gap-2"><code class="flex-1 rounded-xl border hairline bg-ink-950 px-4 py-3 font-mono tracking-[0.14em] text-white" x-text="key"></code><button type="button" class="btn btn-outline" @click="$copy(key)"><x-icon name="copy" class="size-4"/></button></div>
            <p class="mt-2 text-xs text-ink-400">The customer can also reveal it any time in their portal.</p>
        </div>
    @endif
    @if ($license->isAddon())
        <div class="mb-4 flex items-start gap-3 rounded-2xl border border-flare-500/25 bg-flare-500/[0.06] p-4 text-sm text-ink-200">
            <x-icon name="layers" class="mt-0.5 size-4 shrink-0 text-flare-400"/>
            <span>Add-on key for <a href="{{ route('admin.licenses.show', $license->parent) }}" class="font-mono text-white hover:text-volt-300">{{ $license->parent->number }}</a>. It activates only on that license’s live domains{{ $license->included ? ', and follows its dates and status because the plan includes it' : '' }}.</span>
        </div>
    @endif
    @if ($license->status_reason && in_array($license->status, ['suspended', 'revoked'], true))
        <div class="mb-4 rounded-2xl border border-coral-500/25 bg-coral-500/[0.06] p-4 text-sm text-ink-200"><b class="text-coral-400">{{ ucfirst($license->status) }}:</b> {{ $license->status_reason }}</div>
    @endif

    <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_380px]">
        <div class="space-y-4">
            {{-- Summary --}}
            <div class="card card-pad">
                <div class="grid gap-4 text-sm sm:grid-cols-4">
                    <div><div class="text-xs text-ink-500">Key</div>
                        <div class="mt-0.5 flex items-center gap-2" x-data="{ key: null, async reveal() { this.key = (await portalApi(@js(route('admin.licenses.reveal', $license)))).key } }">
                            <code class="font-mono text-[13px] text-ink-100" x-text="key ?? @js($license->maskedKey())"></code>
                            <button type="button" x-show="!key" @click="reveal()" class="text-ink-500 hover:text-white" title="Reveal (recorded in history)"><x-icon name="eye" class="size-3.5"/></button>
                            <button type="button" x-show="key" x-cloak @click="$copy(key)" class="text-ink-500 hover:text-white"><x-icon name="copy" class="size-3.5"/></button>
                        </div></div>
                    <div><div class="text-xs text-ink-500">Domains</div><div class="mt-0.5 text-ink-100">{{ $license->domainsUsed() }} of {{ $license->domainLimit() }} production</div></div>
                    <div><div class="text-xs text-ink-500">{{ $license->expires_at ? 'Renews' : 'Lifetime' }}</div><div class="mt-0.5 text-ink-100">{{ $license->expires_at ? local_time($license->expires_at)->format('M d, Y') : 'No expiry' }}</div></div>
                    <div><div class="text-xs text-ink-500">Updates until</div><div class="mt-0.5 {{ $license->supportExpired() ? 'text-amber-glow' : 'text-ink-100' }}">{{ local_time($license->support_until)?->format('M d, Y') ?? '—' }}</div></div>
                    <div><div class="text-xs text-ink-500">Purchased</div><div class="mt-0.5 text-ink-100">{{ local_time($license->purchased_at)->format('M d, Y') }}</div></div>
                    <div><div class="text-xs text-ink-500">Paid</div><div class="mt-0.5 text-ink-100">{{ fmt_money($license->price_paid, $license->currency) }}</div></div>
                    <div><div class="text-xs text-ink-500">Reminders</div><div class="mt-0.5 text-ink-100">{{ $license->billing_type === 'annual' ? ($license->auto_renew ? 'On' : 'Off') : '—' }}</div></div>
                    <div><div class="text-xs text-ink-500">Max version</div><div class="mt-0.5 text-ink-100">{{ $license->maxVersion() ?? 'Any' }}</div></div>
                    @if ($license->pending_until)<div class="sm:col-span-4 text-xs text-amber-glow">Awaiting payment: works until {{ local_time($license->pending_until)->format('M d, Y') }}.</div>@endif
                    @if ($license->notes)<div class="sm:col-span-4 rounded-xl bg-white/[0.03] p-3 text-xs text-ink-300"><span class="text-ink-500">Note:</span> {{ $license->notes }}</div>@endif
                </div>
            </div>

            {{-- Add-ons (main licenses) --}}
            @unless ($license->isAddon())
                <div class="card">
                    <div class="flex flex-wrap items-center justify-between gap-3 p-5 sm:p-6">
                        <div>
                            <h2 class="font-semibold text-white">Add-ons</h2>
                            <p class="mt-0.5 text-xs text-ink-500">Each add-on has its own key and runs on this license’s domains.</p>
                        </div>
                        @if ($availableAddons->isNotEmpty())
                            <form method="POST" action="{{ route('admin.licenses.addons.store', $license) }}" class="flex flex-wrap gap-2">
                                @csrf
                                <select name="addon_id" class="input !w-auto !py-1.5 text-sm" aria-label="Add-on">@foreach ($availableAddons as $x)<option value="{{ $x->id }}">{{ $x->name }}</option>@endforeach</select>
                                <select name="billing_type" class="input !w-auto !py-1.5 text-sm" aria-label="Billing"><option value="annual">Annual</option><option value="lifetime">Lifetime</option></select>
                                <button class="btn btn-outline btn-sm" onclick="return confirm('Issue this add-on free of charge? Record a sale from Orders if they paid.')"><x-icon name="plus" class="size-3.5"/> Issue add-on</button>
                            </form>
                        @endif
                    </div>
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead><tr><th>Add-on</th><th>Key</th><th>Type</th><th>Domains</th><th>Renews / updates until</th><th>Status</th></tr></thead>
                            <tbody>
                                @forelse ($license->addonLicenses as $a)
                                    @php $ast = $a->displayStatus(); @endphp
                                    <tr class="cursor-pointer" onclick="location.href='{{ route('admin.licenses.show', $a) }}'">
                                        <td><a href="{{ route('admin.licenses.show', $a) }}" class="text-white hover:text-volt-300">{{ $a->addon->name }}</a><div class="font-mono text-[11px] text-ink-500">{{ $a->number }}</div></td>
                                        <td class="font-mono text-xs text-ink-300">{{ $a->maskedKey() }}</td>
                                        <td>@if ($a->included)<span class="badge badge-mint">Included</span>@else<span class="text-ink-300">{{ ucfirst($a->billing_type) }}</span>@endif</td>
                                        <td class="font-mono text-ink-200">{{ $a->domains_live }}</td>
                                        <td class="text-ink-300">{{ $a->included ? 'With '.$license->number : ($a->expires_at ? local_time($a->expires_at)->format('M d, Y') : 'updates until '.local_time($a->support_until)?->format('M d, Y')) }}</td>
                                        <td><span class="badge badge-dot {{ Demo::statusBadge($ast) }}">{{ $ast }}</span></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="py-8 text-center text-ink-500">No add-ons on this license.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @endunless

            {{-- Domains --}}
            <div class="card">
                <div class="flex flex-wrap items-center justify-between gap-3 p-5 sm:p-6">
                    <h2 class="font-semibold text-white">Domains & installations</h2>
                    <form method="POST" action="{{ route('admin.licenses.domains.add', $license) }}" class="flex gap-2">
                        @csrf
                        <input name="domain" value="{{ old('domain') }}" placeholder="portal.customer.com" class="input !py-1.5 text-sm" required>
                        <select name="environment" class="input !w-auto !py-1.5 text-sm"><option value="">Auto</option><option value="production">Production</option><option value="staging">Staging</option></select>
                        <button class="btn btn-outline btn-sm">Add</button>
                    </form>
                </div>
                @error('domain')<p class="-mt-3 px-6 pb-3 text-xs text-coral-400">{{ $message }}</p>@enderror
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead><tr><th>Domain</th><th>Env</th><th>Status</th><th>Version</th><th>Server</th><th>Last check-in</th><th></th></tr></thead>
                        <tbody>
                            @forelse ($license->activations as $a)
                                <tr class="{{ $a->status === 'deactivated' ? 'opacity-50' : '' }}">
                                    <td><span class="mr-2 inline-block size-2 rounded-full {{ $a->isOnline() ? 'bg-mint-400' : 'bg-ink-500' }}"></span><span class="font-medium text-white">{{ $a->domain }}</span></td>
                                    <td><span class="badge {{ $a->environment === 'production' ? 'badge-volt' : 'badge-ink' }}">{{ $a->environment }}</span></td>
                                    <td class="text-ink-300">{{ $a->status }}{{ $a->check_in_requested ? ' · check-in requested' : '' }}</td>
                                    <td class="font-mono text-xs text-ink-300">{{ $a->version ?? '—' }}
                                        <div class="font-sans text-[11px] text-ink-500">{{ $a->update_checked_at ? 'Update check '.local_time($a->update_checked_at)->diffForHumans().($a->update_check_version ? ' (v'.$a->update_check_version.')' : '') : 'No update check yet' }}</div></td>
                                    <td class="font-mono text-xs text-ink-500">{{ $a->ip ?? '—' }}{{ $a->php_version ? ' · PHP '.$a->php_version : '' }}</td>
                                    <td class="text-ink-400">{{ $a->last_check_in_at ? local_time($a->last_check_in_at)->diffForHumans() : 'never' }}</td>
                                    <td class="text-right">
                                        @if ($a->status !== 'deactivated')
                                            <div class="flex justify-end gap-1">
                                                <form method="POST" action="{{ route('admin.licenses.domains.check-in', [$license, $a]) }}">@csrf<button class="btn btn-ghost btn-sm" title="Ask the install to check in now">Check-in</button></form>
                                                <form method="POST" action="{{ route('admin.licenses.domains.deactivate', [$license, $a]) }}" onsubmit="return confirm('Deactivate {{ $a->domain }}? It stops working at its next check-in.')">@csrf @method('DELETE')<button class="btn btn-danger btn-sm">Deactivate</button></form>
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="py-8 text-center text-ink-500">No domains yet. They appear when LabelDesk activates with this key, or add one above.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Orders --}}
            <div class="card">
                <div class="p-5 sm:p-6"><h2 class="font-semibold text-white">Orders</h2></div>
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead><tr><th>Order</th><th>Type</th><th>Total</th><th>Status</th><th>Invoice</th></tr></thead>
                        <tbody>
                            @forelse ($license->orders->sortByDesc('id') as $o)
                                <tr>
                                    <td><div class="font-mono text-xs text-white">{{ $o->number }}</div><div class="text-[11px] text-ink-500">{{ local_time($o->created_at)->format('M d, Y') }}</div></td>
                                    <td class="text-ink-300">{{ $o->kindLabel() }}</td>
                                    <td class="font-mono text-white">{{ fmt_money($o->total, $o->currency) }}</td>
                                    <td><span class="badge badge-dot {{ $statusBadge[$o->status] ?? 'badge-ink' }}">{{ str_replace('_', ' ', $o->status) }}</span></td>
                                    <td>@if ($o->invoice)<a href="{{ route('dashboard.invoices.show', $o->invoice) }}" class="font-mono text-xs text-volt-300">{{ $o->invoice->number }}</a>@else — @endif</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="py-8 text-center text-ink-500">No orders (issued by hand without a sale).</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- History --}}
            <div class="card card-pad">
                <h2 class="font-semibold text-white">History</h2>
                <ol class="relative mt-5 space-y-4 border-l border-white/[0.08] pl-5 text-sm">
                    @foreach ($events as $e)
                        <li class="relative">
                            <span class="absolute -left-[25px] top-1.5 size-2.5 rounded-full ring-4 ring-ink-900 {{ in_array($e->event, ['suspended', 'revoked'], true) ? 'bg-coral-400' : (in_array($e->event, ['key_revealed', 'check_in_requested'], true) ? 'bg-ink-500' : 'bg-volt-500') }}"></span>
                            <div class="text-ink-100">{{ $e->label() }}@if (! empty($e->details['reason']) && $e->event !== 'domain_deactivated') <span class="text-ink-500">· {{ $e->details['reason'] }}</span>@endif</div>
                            <div class="text-xs text-ink-500">{{ local_time($e->created_at)->format('M d, Y H:i') }} · {{ $e->actor }}@if (! empty($e->details['ip'])) · IP {{ $e->details['ip'] }}@endif</div>
                        </li>
                    @endforeach
                </ol>
            </div>
        </div>

        {{-- Actions --}}
        <aside class="space-y-4">
            <form method="POST" action="{{ route('admin.licenses.update', $license) }}" class="card card-pad space-y-3">
                @csrf @method('PUT')
                <h2 class="font-semibold text-white">Edit</h2>
                <div><label class="label" for="plan_id">Plan</label>
                    <select id="plan_id" name="plan_id" class="input">@foreach ($plans as $p)<option value="{{ $p->id }}" @selected($p->id === $license->plan_id)>{{ $p->name }} ({{ $p->domains }} domains)</option>@endforeach</select></div>
                <div><label class="label" for="domains_extra">Extra domains</label><input id="domains_extra" type="number" min="0" name="domains_extra" value="{{ $license->domains_extra }}" class="input font-mono"></div>
                @if ($license->billing_type === 'annual')
                    <div><label class="label" for="expires_at">Renews / expires on</label><input id="expires_at" type="date" name="expires_at" value="{{ $license->expires_at?->format('Y-m-d') }}" class="input"></div>
                @endif
                <div><label class="label" for="support_until">Updates & support until</label><input id="support_until" type="date" name="support_until" value="{{ $license->support_until?->format('Y-m-d') }}" class="input"></div>
                <div><label class="label" for="max_version">Version cap <span class="text-ink-500">(blank = by updates date)</span></label><input id="max_version" name="max_version" value="{{ $license->max_version }}" placeholder="e.g. 4.2.1" class="input font-mono"></div>
                @if ($license->billing_type === 'annual')
                    <label class="flex items-center gap-2 text-sm text-ink-300"><input type="hidden" name="auto_renew" value="0"><input type="checkbox" name="auto_renew" value="1" class="size-4 accent-volt-500" @checked($license->auto_renew)> Renewal reminders</label>
                @endif
                <div><label class="label" for="lnotes">Internal note</label><textarea id="lnotes" name="notes" rows="2" class="input text-sm">{{ $license->notes }}</textarea></div>
                <button class="btn btn-primary btn-sm w-full">Save changes</button>
            </form>

            @if ($license->status !== 'revoked')
                <form method="POST" action="{{ route('admin.licenses.status', $license) }}" class="card card-pad space-y-3" x-data="{ status: @js($license->status === 'suspended' ? 'active' : 'suspended') }">
                    @csrf
                    <h2 class="font-semibold text-white">Status</h2>
                    <p class="text-xs text-ink-500">Suspended and revoked licenses stop working on customers’ sites at the next check-in (within 24 h). The reason is shown to the customer.</p>
                    <div class="grid grid-cols{{ $license->status === 'suspended' ? '-2' : '-2' }} gap-2 text-sm">
                        @if ($license->status === 'suspended')
                            <label class="cursor-pointer"><input type="radio" name="status" value="active" x-model="status" class="peer sr-only"><span class="block rounded-xl border border-white/[0.08] py-2 text-center peer-checked:border-mint-500/60 peer-checked:bg-mint-500/10">Reinstate</span></label>
                        @else
                            <label class="cursor-pointer"><input type="radio" name="status" value="suspended" x-model="status" class="peer sr-only"><span class="block rounded-xl border border-white/[0.08] py-2 text-center peer-checked:border-amber-glow/60 peer-checked:bg-amber-glow/10">Suspend</span></label>
                        @endif
                        <label class="cursor-pointer"><input type="radio" name="status" value="revoked" x-model="status" class="peer sr-only"><span class="block rounded-xl border border-white/[0.08] py-2 text-center peer-checked:border-coral-500/60 peer-checked:bg-coral-500/10">Revoke</span></label>
                    </div>
                    <div><label class="label" for="reason">Reason</label><input id="reason" name="reason" class="input" required minlength="3" placeholder="e.g. Unpaid invoice MD-2026-000123"></div>
                    <div x-show="status === 'revoked'" x-cloak><label class="label" for="confirm">Type <span class="font-mono">{{ $license->number }}</span> to confirm. Revoking is permanent.</label><input id="confirm" name="confirm" class="input font-mono" autocomplete="off"></div>
                    @error('confirm')<p class="text-xs text-coral-400">{{ $message }}</p>@enderror
                    @error('status')<p class="text-xs text-coral-400">{{ $message }}</p>@enderror
                    <button class="btn btn-sm w-full" :class="status === 'active' ? 'btn-primary' : 'btn-danger'" x-text="{ active: 'Reinstate license', suspended: 'Suspend license', revoked: 'Revoke license' }[status]"></button>
                </form>
            @endif

            <div class="card card-pad space-y-3">
                <h2 class="font-semibold text-white">Key</h2>
                <p class="text-xs text-ink-500">Generate a new key if the old one leaked. Every install must be activated again with the new key.</p>
                <form method="POST" action="{{ route('admin.licenses.regenerate', $license) }}" onsubmit="return confirm('Generate a new key for {{ $license->number }}? The old key stops working at the next check-in.')">@csrf<button class="btn btn-outline btn-sm w-full"><x-icon name="refresh" class="size-3.5"/> Generate new key</button></form>
            </div>
        </aside>
    </div>
</x-layouts.app>
