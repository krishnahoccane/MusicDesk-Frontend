@php $statusBadge = ['paid' => 'badge-mint', 'awaiting_payment' => 'badge-amber', 'pending' => 'badge-ink', 'failed' => 'badge-coral', 'cancelled' => 'badge-ink', 'refunded' => 'badge-sky']; @endphp
<x-layouts.app area="admin" title="Site admin" subtitle="Storefront, payments and invoicing for {{ setting('branding.site_name') }}.">
    <x-slot:eyebrow>Admin · {{ local_time(now())->format('l, F j') }}</x-slot:eyebrow>
    <x-slot:heading>Overview</x-slot:heading>
    <x-slot:actions>
        <a href="{{ route('home') }}" target="_blank" class="btn btn-outline btn-sm"><x-icon name="globe" class="size-3.5"/> View site</a>
        <a href="https://billing.twsd.me" target="_blank" rel="noopener" class="btn btn-outline btn-sm">billing.twsd.me <x-icon name="arrow-up-right" class="size-3.5"/></a>
    </x-slot:actions>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat label="Revenue this month (≈ USD)" value="{{ '$'.number_format($revenueUsd, 0) }}" icon="wallet" color="#22d9a0"/>
        <x-stat label="Orders this month" value="{{ $ordersCount }}" icon="receipt" color="#7c5cff"/>
        <a href="{{ route('admin.orders', ['status' => 'awaiting_payment']) }}"><x-stat label="Awaiting bank transfer" value="{{ $awaiting }}" icon="alert" color="#ffb547"/></a>
        <x-stat label="Organisations" value="{{ $orgs }}" icon="users" color="#ff3fd2"/>
    </div>

    <div class="mt-4 grid gap-4 lg:grid-cols-3">
        <div class="card card-pad">
            <h2 class="font-semibold text-white">Health</h2>
            <ul class="mt-4 space-y-3 text-sm">
                <li class="flex items-start gap-3">
                    <span class="mt-1 size-2 rounded-full {{ $gatewaysOn->isNotEmpty() ? 'bg-mint-400' : 'bg-coral-400' }}"></span>
                    <span class="flex-1"><span class="block text-ink-100">Payment gateways</span><span class="text-xs text-ink-500">{{ $gatewaysOn->isNotEmpty() ? $gatewaysOn->implode(', ').' enabled' : 'None enabled. Customers can’t pay.' }}</span></span>
                    <a href="{{ route('admin.gateways') }}" class="text-xs text-volt-300">Manage</a>
                </li>
                <li class="flex items-start gap-3">
                    @php $stale = ! $ratesUpdated || \Illuminate\Support\Carbon::parse($ratesUpdated)->lt(now()->subHours(36)); @endphp
                    <span class="mt-1 size-2 rounded-full {{ $stale ? 'bg-amber-glow' : 'bg-mint-400' }}"></span>
                    <span class="flex-1"><span class="block text-ink-100">Exchange rates</span><span class="text-xs text-ink-500">{{ $ratesUpdated ? 'Updated '.\Illuminate\Support\Carbon::parse($ratesUpdated)->diffForHumans() : 'Never updated' }}{{ $stale ? '. Check the scheduler is running.' : '' }}</span></span>
                    <a href="{{ route('admin.currencies') }}" class="text-xs text-volt-300">Rates</a>
                </li>
                <li class="flex items-start gap-3">
                    <span class="mt-1 size-2 rounded-full {{ config('services.wcc.enabled') ? 'bg-mint-400' : 'bg-amber-glow' }}"></span>
                    <span class="flex-1"><span class="block text-ink-100">billing.twsd.me connection</span><span class="text-xs text-ink-500">{{ config('services.wcc.enabled') ? 'Live' : 'Local mode: keys are generated on this server (set WCC_BILLING_ENABLED=true)' }}</span></span>
                </li>
                <li class="flex items-start gap-3">
                    <span class="mt-1 size-2 rounded-full {{ $unsynced ? 'bg-amber-glow' : 'bg-mint-400' }}"></span>
                    <span class="flex-1"><span class="block text-ink-100">Orders recorded in billing</span><span class="text-xs text-ink-500">{{ $unsynced ? $unsynced.' order(s) need attention in Orders' : 'All recorded. New organisations appear in billing with their first order.' }}</span></span>
                    @if ($unsynced)<a href="{{ route('admin.orders') }}" class="text-xs text-volt-300">Review</a>@endif
                </li>
            </ul>
        </div>

        <div class="card lg:col-span-2">
            <div class="flex items-center justify-between p-5 pb-2 sm:p-6 sm:pb-2">
                <h2 class="font-semibold text-white">Recent orders</h2>
                <a href="{{ route('admin.orders') }}" class="text-xs text-volt-300 hover:text-white">All orders →</a>
            </div>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th>Order</th><th>Organisation</th><th>Plan</th><th>Total</th><th>Status</th></tr></thead>
                    <tbody>
                        @forelse ($recentOrders as $o)
                            <tr>
                                <td><div class="font-mono text-xs text-white">{{ $o->number }}</div><div class="text-[11px] text-ink-500">{{ local_time($o->created_at)->format('M d, H:i') }}</div></td>
                                <td class="text-ink-200">{{ $o->organisation?->name }}</td>
                                <td class="text-ink-300">{{ $o->plan?->name }} · {{ ucfirst($o->billing_type) }}</td>
                                <td class="font-mono text-white">{{ fmt_money($o->total, $o->currency) }}</td>
                                <td><span class="badge badge-dot {{ $statusBadge[$o->status] ?? 'badge-ink' }}">{{ str_replace('_', ' ', $o->status) }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-10 text-center text-ink-500">No orders yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <h2 class="eyebrow mb-3 mt-10">Manage the storefront</h2>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            ['Branding', 'Logo, colors, fonts', route('admin.settings', 'branding'), 'sparkles'],
            ['Homepage text', 'Headlines and copy', route('admin.settings', 'home'), 'file'],
            ['Pricing plans', 'Plans, add-ons, coupons', route('admin.plans'), 'tag'],
            ['Legal pages', 'Terms, privacy, refunds', route('admin.pages'), 'shield'],
            ['Contact & social', 'Emails, phone, links', route('admin.settings', 'contact'), 'mail'],
            ['Payment gateways', 'Razorpay, Stripe, PayPal…', route('admin.gateways'), 'card'],
            ['Currencies', 'Rates & charm pricing', route('admin.currencies'), 'globe'],
            ['Invoicing', 'Company details, tax', route('admin.settings', 'invoice'), 'receipt'],
        ] as [$t, $d, $href, $ic])
            <a href="{{ $href }}" class="card card-pad group flex items-center gap-4 transition hover:border-white/10">
                <span class="grid size-11 place-items-center rounded-xl bg-white/[0.04] text-volt-300 ring-1 ring-white/[0.06]"><x-icon :name="$ic" class="size-5"/></span>
                <span class="flex-1"><span class="block font-medium text-white">{{ $t }}</span><span class="text-xs text-ink-500">{{ $d }}</span></span>
                <x-icon name="chevron-right" class="text-ink-500 transition group-hover:translate-x-0.5 group-hover:text-white"/>
            </a>
        @endforeach
    </div>
</x-layouts.app>
