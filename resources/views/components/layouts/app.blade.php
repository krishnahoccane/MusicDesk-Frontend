@props(['title' => 'Dashboard', 'subtitle' => null, 'area' => 'portal'])
@php
    use App\Support\Demo;
    $user = auth()->user();
    $org = $user?->organisation;
    $isAdmin = $area === 'admin';
    // Counts that need attention, shown as coral badges in the admin menu.
    $openAlerts = $isAdmin && $user?->canAdmin('security') ? \App\Models\LicenseAlert::where('status', 'open')->count() : 0;
    $openTickets = $isAdmin && $user?->canAdmin('support') ? \App\Models\SupportTicket::where('status', 'open')->count() : 0;
    $nav = $isAdmin
        ? [
            'Overview' => [
                ['Dashboard', 'admin.dashboard', [], 'grid', null],
                ['Orders', 'admin.orders', [], 'receipt', null],
                ['Invoices', 'admin.invoices', [], 'file', null],
            ],
            'Licensing' => [
                ['Licenses', 'admin.licenses', [], 'key', null],
                ['Status', 'admin.license-status', [], 'shield', $openAlerts ?: null, 'alert'],
                ['Customers', 'admin.customers', [], 'users', null],
                ['Releases', 'admin.releases', [], 'download', null],
            ],
            'Support' => [
                ['Tickets', 'admin.support', [], 'headphones', $openTickets ?: null, 'alert'],
            ],
            'Storefront' => [
                ['Branding', 'admin.settings', ['branding'], 'sparkles', null],
                ['Homepage text', 'admin.settings', ['home'], 'file', null],
                ['Pricing plans', 'admin.plans', [], 'tag', null],
                ['Legal pages', 'admin.pages', [], 'shield', null],
                ['Contact & social', 'admin.settings', ['contact'], 'mail', null],
            ],
            'Payments' => [
                ['Payment gateways', 'admin.gateways', [], 'card', null],
                ['Currencies', 'admin.currencies', [], 'globe', null],
                ['Invoicing', 'admin.settings', ['invoice'], 'receipt', null],
                ['Localisation', 'admin.settings', ['general'], 'settings', null],
            ],
            'Administration' => [
                ['Team & roles', 'admin.team', [], 'user-plus', null],
            ],
        ]
        : [
            'Portal' => [
                ['Overview', 'dashboard', [], 'home', null],
                ['Licenses', 'dashboard.licenses', [], 'key', null],
                ['Downloads', 'dashboard.downloads', [], 'download', 'v'.\App\Models\Release::latestVersion()],
            ],
            'Account' => [
                ['Billing', 'dashboard.billing', [], 'card', null],
                ['Support', 'dashboard.support', [], 'headphones', null],
                ['Settings', 'dashboard.settings', [], 'settings', null],
            ],
        ];
    // Only show admin areas the member's role includes (AdminRole::PERMISSIONS).
    if ($isAdmin) {
        $areaFor = fn ($route, $params) => match (true) {
            $route === 'admin.dashboard' => null,
            $route === 'admin.settings' => \App\Http\Middleware\EnsureAdminPermission::SETTINGS[$params[0]] ?? 'payments',
            in_array($route, ['admin.orders', 'admin.invoices'], true) => 'sales',
            $route === 'admin.license-status' => 'security',
            in_array($route, ['admin.plans', 'admin.pages'], true) => 'storefront',
            in_array($route, ['admin.gateways', 'admin.currencies'], true) => 'payments',
            default => substr($route, 6),   // admin.licenses → licenses, admin.team → team …
        };
        $nav = collect($nav)->map(fn ($items) => array_values(array_filter($items, fn ($i) => ($a = $areaFor($i[1], $i[2])) === null || $user?->canAdmin($a))))
            ->filter()->all();
    }
    $isActive = fn ($route, $params) => (request()->routeIs($route) || ($route !== 'dashboard' && request()->routeIs($route.'.*')))
        && (empty($params) || request()->route('group') === $params[0]);
@endphp
<x-layouts.base :title="$title" x-data="{ side: false }">
    <div aria-hidden="true" class="pointer-events-none fixed inset-0 -z-10">
        <div class="absolute -top-56 left-1/3 h-[520px] w-[900px] rounded-full opacity-[.10] blur-[120px]" style="background: radial-gradient(closest-side, {{ $isAdmin ? 'var(--color-flare-500)' : 'var(--color-volt-500)' }}, transparent)"></div>
    </div>

    <div x-show="side" x-cloak x-transition.opacity @click="side = false" class="fixed inset-0 z-40 bg-black/60 backdrop-blur-sm lg:hidden"></div>

    {{-- Sidebar --}}
    <aside class="fixed inset-y-0 left-0 z-50 flex w-[264px] -translate-x-full flex-col border-r hairline bg-ink-950/95 backdrop-blur-xl transition-transform duration-300 lg:translate-x-0"
           :class="side && '!translate-x-0'">
        <div class="flex h-16 items-center justify-between px-5">
            <a href="{{ route('home') }}"><x-logo size="sm"/></a>
            @if ($isAdmin)<span class="badge badge-flare font-mono !text-[10px]">ADMIN</span>@endif
            <button @click="side = false" class="btn btn-ghost btn-sm !p-1.5 lg:hidden" aria-label="Close menu"><x-icon name="x"/></button>
        </div>

        @if (! $isAdmin && $org)
            <div class="mx-3 mb-2 flex items-center gap-2.5 rounded-xl border hairline bg-white/[0.02] p-2.5">
                <span class="grid size-8 place-items-center rounded-lg bg-gradient-to-br from-amber-glow to-flare-500 text-xs font-semibold text-ink-950">{{ $org->initials() }}</span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-[13px] font-medium text-white">{{ $org->name }}</span>
                    <span class="block text-[11px] text-ink-400">Customer since {{ local_time($org->created_at)->format('Y') }}</span>
                </span>
            </div>
        @elseif ($isAdmin)
            <p class="mx-5 mb-2 text-[11px] leading-relaxed text-ink-500">Site admin for {{ parse_url(config('app.url'), PHP_URL_HOST) }}: licensing, support, payments and storefront. <span class="font-mono">v{{ config('app.version') }}</span></p>
        @endif

        <nav class="flex-1 space-y-6 overflow-y-auto px-3 py-3">
            @foreach ($nav as $group => $items)
                <div>
                    <div class="eyebrow mb-2 px-3 !text-[10px]">{{ $group }}</div>
                    <div class="space-y-0.5">
                        @foreach ($items as $item)
                            @php [$label, $route, $params, $icon, $badge] = $item; $tone = $item[5] ?? null; @endphp
                            <a href="{{ route($route, $params) }}" class="nav-link {{ $isActive($route, $params) ? 'active' : '' }}">
                                <x-icon :name="$icon" class="size-[17px]"/>
                                <span class="flex-1">{{ $label }}</span>
                                @if ($badge)<span class="rounded-md px-1.5 py-px font-mono text-[10px] {{ $tone === 'alert' ? 'bg-coral-500/15 text-coral-400' : 'bg-mint-500/15 text-mint-400' }}">{{ $badge }}</span>@endif
                            </a>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </nav>

        @unless ($isAdmin)
            <div class="relative mx-3 mb-3 overflow-hidden rounded-2xl border hairline p-4">
                <div class="absolute inset-0 opacity-60" style="background: radial-gradient(120% 80% at 100% 0%, color-mix(in oklab, var(--color-flare-500) 25%, transparent), transparent 60%), radial-gradient(100% 80% at 0% 100%, color-mix(in oklab, var(--color-volt-500) 30%, transparent), transparent 60%)"></div>
                <div class="relative">
                    <div class="flex items-center gap-2 text-[13px] font-medium text-white"><x-icon name="sparkles" class="size-4 text-amber-glow"/> {{ setting('branding.product_name') }} {{ \App\Models\Release::latestVersion() }} is out</div>
                    <p class="mt-1.5 text-xs leading-relaxed text-ink-300">Free with an active license.</p>
                    <a href="{{ route('dashboard.downloads') }}" class="mt-3 inline-flex items-center gap-1 text-xs font-medium text-volt-300 hover:text-white">See what’s new <x-icon name="arrow-right" class="size-3"/></a>
                </div>
            </div>
        @endunless

        <div class="border-t hairline p-3">
            @if ($isAdmin && $user?->organisation_id)
                <a href="{{ route('dashboard') }}" class="nav-link"><x-icon name="home" class="size-[17px]"/> Customer portal</a>
            @elseif (! $isAdmin && $user?->is_admin)
                <a href="{{ route('admin.dashboard') }}" class="nav-link"><x-icon name="shield" class="size-[17px]"/> Site admin</a>
            @endif
            <a href="{{ route('home') }}" class="nav-link"><x-icon name="globe" class="size-[17px]"/> View site</a>
            <form method="POST" action="{{ route('logout') }}">@csrf<button class="nav-link w-full"><x-icon name="logout" class="size-[17px]"/> Sign out</button></form>
        </div>
    </aside>

    {{-- Main --}}
    <div class="lg:pl-[264px]">
        <header class="sticky top-0 z-30 border-b hairline bg-ink-950/70 backdrop-blur-xl">
            <div class="flex h-16 items-center gap-3 px-4 sm:px-6 lg:px-8">
                <button @click="side = true" class="btn btn-ghost btn-sm -ml-2 lg:hidden" aria-label="Open menu"><x-icon name="menu" class="size-5"/></button>

                <div class="hidden items-center gap-2 text-xs text-ink-500 md:flex" title="Dates are stored in UTC and shown in your time zone">
                    <x-icon name="globe" class="size-3.5"/> {{ $localeTimezone ?? 'UTC' }} · {{ local_time(now())->format('D, M j · H:i') }}
                </div>

                <div class="ml-auto flex items-center gap-1.5">
                    <x-currency-switcher/>
                    @unless ($isAdmin)
                        <a href="{{ route('pricing') }}" class="btn btn-primary btn-sm hidden sm:inline-flex"><x-icon name="plus" class="size-3.5"/> Buy license</a>
                    @endunless

                    <div x-data="{ o: false }" class="relative">
                        <button @click="o = !o" class="flex items-center gap-2 rounded-xl p-1 pr-2 transition hover:bg-white/[0.05]">
                            <span class="grid size-8 place-items-center rounded-lg bg-gradient-to-br from-volt-500 to-flare-500 text-xs font-semibold text-white">{{ $user?->initials() }}</span>
                            <span class="hidden text-left md:block">
                                <span class="block text-[13px] font-medium leading-tight text-white">{{ $user?->name }}</span>
                                <span class="block text-[11px] capitalize leading-tight text-ink-400">{{ $isAdmin ? ($user?->adminRole?->name ?? 'Site admin') : $user?->role }}</span>
                            </span>
                        </button>
                        <div x-show="o" x-cloak @click.outside="o = false" x-transition.origin.top.right
                             class="absolute right-0 mt-2 w-56 rounded-2xl glass !bg-ink-900/95 p-1.5 shadow-2xl shadow-black/60">
                            @if ($user?->organisation_id)
                                <a href="{{ route('dashboard.settings') }}" class="nav-link"><x-icon name="settings"/> Settings</a>
                                <a href="{{ route('dashboard.billing') }}" class="nav-link"><x-icon name="card"/> Billing</a>
                            @endif
                            @if ($user?->is_admin)
                                <a href="{{ route('admin.dashboard') }}" class="nav-link"><x-icon name="shield"/> Site admin</a>
                            @endif
                            <div class="my-1 border-t hairline"></div>
                            <form method="POST" action="{{ route('logout') }}">@csrf<button class="nav-link w-full"><x-icon name="logout"/> Sign out</button></form>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <main class="px-4 py-8 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-[1400px]">
                <div class="mb-8 flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
                    <div>
                        @isset($eyebrow)<div class="eyebrow mb-2">{{ $eyebrow }}</div>@endisset
                        <h1 class="display text-[28px] sm:text-[32px]">{{ $heading ?? $title }}</h1>
                        @if ($subtitle)<p class="mt-1.5 text-sm text-ink-400">{{ $subtitle }}</p>@endif
                    </div>
                    @isset($actions)<div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>@endisset
                </div>
                {{ $slot }}
            </div>
        </main>
    </div>
</x-layouts.base>
