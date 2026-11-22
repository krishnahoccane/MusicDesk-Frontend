@php use App\Models\SupportTicket; @endphp
<x-layouts.app area="admin" title="Support tickets" subtitle="Questions from customers. Replies are emailed to them and shown in their portal.">
    <x-slot:eyebrow>Admin · Support</x-slot:eyebrow>

    <div class="grid gap-4 sm:grid-cols-3">
        @foreach ([['open', 'Needs reply', '#ff5757'], ['awaiting', 'Awaiting customer', '#ffb547'], ['resolved', 'Resolved', '#22d9a0']] as [$k, $label, $color])
            <a href="{{ route('admin.support', ['status' => $k]) }}" class="card card-pad block transition hover:border-white/10 {{ $status === $k ? '!border-white/15' : '' }}">
                <div class="text-[13px] text-ink-400">{{ $label }}</div>
                <div class="mt-2 text-2xl font-semibold" style="color: {{ $color }}">{{ $counts[$k] ?? 0 }}</div>
            </a>
        @endforeach
    </div>

    <div class="card mt-4">
        <form method="GET" class="flex flex-col gap-3 border-b hairline p-4 lg:flex-row lg:items-center">
            <div class="relative flex-1"><x-icon name="search" class="absolute left-3 top-1/2 -translate-y-1/2 text-ink-500"/>
                <input name="q" value="{{ request('q') }}" class="input !py-2 pl-9 text-[13px]" placeholder="Ticket number, subject, customer, email or domain"></div>
            <select name="status" class="input !w-auto !py-2 text-[13px]">
                @foreach (['open' => 'Needs reply', 'awaiting' => 'Awaiting customer', 'resolved' => 'Resolved', 'all' => 'All statuses'] as $k => $l)
                    <option value="{{ $k }}" @selected($status === $k)>{{ $l }}</option>
                @endforeach
            </select>
            <select name="priority" class="input !w-auto !py-2 text-[13px]">
                <option value="">Any priority</option>
                @foreach (['high' => 'High', 'normal' => 'Normal', 'low' => 'Low'] as $k => $l)<option value="{{ $k }}" @selected(request('priority') === $k)>{{ $l }}</option>@endforeach
            </select>
            <label class="flex items-center gap-2 text-[13px] text-ink-300"><input type="checkbox" name="mine" value="1" @checked(request('mine')) class="accent-volt-500"> Assigned to me</label>
            <button class="btn btn-outline btn-sm">Filter</button>
        </form>
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>Ticket</th><th>Customer</th><th>Category</th><th>Priority</th><th>Last activity</th><th>Assignee</th><th>Status</th></tr></thead>
                <tbody>
                    @forelse ($tickets as $t)
                        <tr class="cursor-pointer" onclick="location.href='{{ route('admin.support.show', $t) }}'">
                            <td class="max-w-[360px]">
                                <a href="{{ route('admin.support.show', $t) }}" class="block truncate font-medium text-white hover:text-volt-300">{{ $t->subject }}</a>
                                <div class="font-mono text-[11px] text-ink-500">{{ $t->number }}{{ $t->license ? ' · '.$t->license->number : '' }}{{ $t->domain ? ' · '.$t->domain : '' }} · {{ $t->public_messages_count }} msg</div>
                            </td>
                            <td><div class="text-ink-100">{{ $t->organisation->name }}</div><div class="text-[11px] text-ink-500">{{ $t->license?->plan?->name ?? 'No license' }}</div></td>
                            <td class="text-ink-300">{{ $t->categoryLabel() }}</td>
                            <td><span class="badge {{ ['high' => 'badge-coral', 'normal' => 'badge-ink', 'low' => 'badge-ink'][$t->priority] ?? 'badge-ink' }}">{{ ucfirst($t->priority) }}</span></td>
                            <td class="whitespace-nowrap text-ink-300">{{ $t->last_reply_at?->diffForHumans() }}@if ($t->overdue())<div class="text-[11px] text-coral-400">Over {{ $t->slaHours() }} h target</div>@endif</td>
                            <td class="text-ink-300">{{ $t->assignee?->name ?? '—' }}</td>
                            <td><span class="badge badge-dot {{ $t->badge(true) }}">{{ $t->statusLabel(true) }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-12 text-center text-ink-500">{{ $status === 'open' ? 'Inbox zero. No tickets need a reply.' : 'No tickets match.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($tickets->hasPages())<div class="border-t hairline px-4 py-3 text-sm">{{ $tickets->links() }}</div>@endif
    </div>
</x-layouts.app>
