@php $statusBadge = ['paid' => 'badge-mint', 'open' => 'badge-amber', 'refunded' => 'badge-sky', 'void' => 'badge-ink']; @endphp
<x-layouts.app area="admin" title="Invoices" subtitle="Every invoice is generated here and reported to billing.twsd.me with a signed PDF link.">
    <x-slot:eyebrow>Admin · Overview</x-slot:eyebrow>
    <x-slot:actions><a href="{{ route('admin.settings', 'invoice') }}" class="btn btn-outline btn-sm"><x-icon name="settings" class="size-3.5"/> Invoice settings</a></x-slot:actions>

    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>Number</th><th>Organisation</th><th>Issued</th><th>Description</th><th>Total</th><th>Tax</th><th>Status</th><th></th></tr></thead>
            <tbody>
                @forelse ($invoices as $i)
                    <tr>
                        <td class="font-mono text-xs text-white">{{ $i->number }}</td>
                        <td class="text-ink-200">{{ $i->buyer['name'] ?? $i->organisation?->name }}</td>
                        <td class="text-ink-300">{{ local_time($i->issued_at)->format('M d, Y') }}</td>
                        <td class="max-w-[240px] truncate text-ink-300">{{ $i->description() }}</td>
                        <td class="font-mono text-white">{{ fmt_money($i->total, $i->currency) }}</td>
                        <td class="font-mono text-ink-400">{{ fmt_money($i->tax, $i->currency) }}</td>
                        <td><span class="badge badge-dot {{ $statusBadge[$i->status] ?? 'badge-ink' }}">{{ $i->status }}</span></td>
                        <td class="text-right">
                            <a href="{{ route('dashboard.invoices.show', $i) }}" class="btn btn-ghost btn-sm">View</a>
                            <a href="{{ route('dashboard.invoices.pdf', $i) }}" class="btn btn-ghost btn-sm"><x-icon name="download" class="size-3.5"/></a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="py-12 text-center text-ink-500">No invoices yet.</td></tr>
                @endforelse
            </tbody>
        </table>
        @if ($invoices->hasPages())<div class="border-t hairline px-4 py-3 text-sm">{{ $invoices->links() }}</div>@endif
    </div>
</x-layouts.app>
