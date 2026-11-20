@php use App\Support\Demo; @endphp
<x-layouts.app area="admin" title="Licenses" subtitle="Every LabelDesk license: keys, domains, renewals and status.">
    <x-slot:eyebrow>Admin · Licensing</x-slot:eyebrow>
    <x-slot:actions>
        <a href="{{ route('admin.licenses.create') }}" class="btn btn-primary btn-sm"><x-icon name="key" class="size-3.5"/> Issue license</a>
    </x-slot:actions>

    <div class="grid gap-4 sm:grid-cols-3 xl:grid-cols-6">
        @foreach ([['Total', $stats['total'], null, '#7c5cff'], ['Active', $stats['active'], 'active', '#22d9a0'], ['Renewing ≤ 30d', $stats['expiring'], 'expiring', '#ffb547'], ['Awaiting payment', $stats['pending'], 'pending', '#5cc8ff'], ['Suspended / revoked', $stats['suspended'], 'suspended', '#ff5757'], ['Installs online', $stats['installs'], null, '#ff3fd2']] as [$label, $value, $filter, $color])
            <a @if ($filter) href="{{ route('admin.licenses', ['status' => $filter]) }}" @endif class="card card-pad block {{ $filter ? 'transition hover:border-white/10' : '' }}">
                <div class="text-[13px] text-ink-400">{{ $label }}</div>
                <div class="mt-2 text-2xl font-semibold text-white" style="color: {{ $color }}">{{ $value }}</div>
            </a>
        @endforeach
    </div>

    <div class="card mt-4">
        <form method="GET" class="flex flex-col gap-3 border-b hairline p-4 lg:flex-row lg:items-center">
            <div class="relative flex-1"><x-icon name="search" class="absolute left-3 top-1/2 -translate-y-1/2 text-ink-500"/>
                <input name="q" value="{{ request('q') }}" class="input !py-2 pl-9 text-[13px]" placeholder="License number, customer, email, domain or last 4 of key"></div>
            <select name="status" class="input !w-auto !py-2 text-[13px]">
                <option value="">All statuses</option>
                @foreach (['active' => 'Active', 'expiring' => 'Renewing ≤ 30 days', 'expired' => 'Expired', 'pending' => 'Awaiting payment', 'suspended' => 'Suspended', 'revoked' => 'Revoked'] as $k => $l)
                    <option value="{{ $k }}" @selected(request('status') === $k)>{{ $l }}</option>
                @endforeach
            </select>
            <select name="plan" class="input !w-auto !py-2 text-[13px]">
                <option value="">All plans</option>
                @foreach ($plans as $p)<option value="{{ $p->code }}" @selected(request('plan') === $p->code)>{{ $p->name }}</option>@endforeach
            </select>
            <button class="btn btn-outline btn-sm">Filter</button>
        </form>
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>License</th><th>Customer</th><th>Plan</th><th>Domains</th><th>Renews / updates until</th><th>Status</th></tr></thead>
                <tbody>
                    @forelse ($licenses as $l)
                        @php $st = $l->displayStatus(); @endphp
                        <tr class="cursor-pointer" onclick="location.href='{{ route('admin.licenses.show', $l) }}'">
                            <td><a href="{{ route('admin.licenses.show', $l) }}" class="font-mono text-xs text-white hover:text-volt-300">{{ $l->number }}</a><div class="font-mono text-[11px] text-ink-500">{{ $l->maskedKey() }}</div></td>
                            <td><div class="text-ink-100">{{ $l->organisation->name }}</div><div class="text-[11px] text-ink-500">{{ $l->organisation->email }}</div></td>
                            <td class="text-ink-300">{{ $l->plan->name }} · {{ ucfirst($l->billing_type) }}</td>
                            <td class="font-mono text-ink-200">{{ $l->domains_live }} / {{ $l->domainLimit() }}</td>
                            <td class="text-ink-300">{{ $l->expires_at ? local_time($l->expires_at)->format('M d, Y') : ($l->support_until ? 'updates until '.local_time($l->support_until)->format('M d, Y') : '—') }}</td>
                            <td><span class="badge badge-dot {{ Demo::statusBadge($st) }}">{{ $st }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-12 text-center text-ink-500">No licenses match.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($licenses->hasPages())<div class="border-t hairline px-4 py-3 text-sm">{{ $licenses->links() }}</div>@endif
    </div>
</x-layouts.app>
