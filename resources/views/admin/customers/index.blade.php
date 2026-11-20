<x-layouts.app area="admin" title="Customers" subtitle="Every organisation: team, licenses, orders and invoices.">
    <x-slot:eyebrow>Admin · Licensing</x-slot:eyebrow>
    <div class="card">
        <form method="GET" class="flex gap-2 border-b hairline p-4">
            <div class="relative flex-1"><x-icon name="search" class="absolute left-3 top-1/2 -translate-y-1/2 text-ink-500"/>
                <input name="q" value="{{ request('q') }}" class="input !py-2 pl-9 text-[13px]" placeholder="Organisation, email or team member"></div>
            <button class="btn btn-outline btn-sm">Search</button>
        </form>
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>Organisation</th><th>Country</th><th>Team</th><th>Licenses</th><th>Revenue (≈ USD)</th><th>Customer since</th></tr></thead>
                <tbody>
                    @forelse ($customers as $c)
                        <tr class="cursor-pointer" onclick="location.href='{{ route('admin.customers.show', $c) }}'">
                            <td><a href="{{ route('admin.customers.show', $c) }}" class="font-medium text-white hover:text-volt-300">{{ $c->name }}</a><div class="text-[11px] text-ink-500">{{ $c->email }}</div></td>
                            <td class="text-ink-300">{{ $c->country ?? '—' }}</td>
                            <td class="text-ink-300">{{ $c->users_count }}</td>
                            <td class="text-ink-300">{{ $c->active_licenses_count }} active{{ $c->licenses_count > $c->active_licenses_count ? ' · '.$c->licenses_count.' total' : '' }}</td>
                            <td class="font-mono text-white">${{ number_format($revenue[$c->id] ?? 0, 0) }}</td>
                            <td class="text-ink-400">{{ local_time($c->created_at)->format('M d, Y') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-12 text-center text-ink-500">No customers yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($customers->hasPages())<div class="border-t hairline px-4 py-3 text-sm">{{ $customers->links() }}</div>@endif
    </div>
</x-layouts.app>
