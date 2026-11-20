@props(['title' => null])
<x-layouts.base :title="$title">
    {{-- Ambient background --}}
    <div aria-hidden="true" class="pointer-events-none fixed inset-0 -z-10 overflow-hidden">
        <div class="absolute -top-40 left-1/2 h-[640px] w-[1100px] -translate-x-1/2 rounded-full opacity-[.22] blur-[120px]"
             style="background: radial-gradient(closest-side, #7c5cff, transparent), radial-gradient(closest-side at 70% 60%, #ff3fd2, transparent);"></div>
        <div class="absolute inset-0 grid-bg"></div>
    </div>

    {{-- Nav --}}
    <header x-data="{ open: false, scrolled: false }" @scroll.window="scrolled = window.scrollY > 12"
            class="sticky top-0 z-50 transition-all duration-300" :class="scrolled ? 'py-2' : 'py-4'">
        <div class="mx-auto max-w-7xl px-4 sm:px-6">
            <nav class="flex items-center justify-between rounded-2xl px-3 py-2 transition-all duration-300"
                 :class="scrolled ? 'glass shadow-2xl shadow-black/40' : 'border border-transparent'">
                <a href="{{ route('home') }}" class="pl-1"><x-logo/></a>
                <div class="hidden items-center gap-1 md:flex">
                    @foreach ([['Product', route('home').'#product'], ['White-label', route('home').'#white-label'], ['How it works', route('home').'#how'], ['Pricing', route('pricing')]] as [$label, $href])
                        <a href="{{ $href }}" class="rounded-lg px-3 py-2 text-[13.5px] text-ink-300 transition hover:text-white">{{ $label }}</a>
                    @endforeach
                </div>
                <div class="hidden items-center gap-2 md:flex">
                    <x-currency-switcher/>
                    @auth
                        <a href="{{ auth()->user()->organisation_id ? route('dashboard') : route('admin.dashboard') }}" class="btn btn-ghost btn-sm">My portal</a>
                    @else
                        <a href="{{ route('login') }}" class="btn btn-ghost btn-sm">Customer login</a>
                    @endauth
                    <a href="{{ route('pricing') }}" class="btn btn-white btn-sm">Buy a license <x-icon name="arrow-right" class="size-3.5"/></a>
                </div>
                <button @click="open = !open" class="btn btn-ghost btn-sm md:hidden" aria-label="Menu"><x-icon name="menu" class="size-5"/></button>
            </nav>
            <div x-show="open" x-cloak x-transition class="mt-2 rounded-2xl glass p-3 md:hidden">
                <a href="{{ route('home') }}#product" class="nav-link">Product</a>
                <a href="{{ route('home') }}#white-label" class="nav-link">White-label</a>
                <a href="{{ route('home') }}#how" class="nav-link">How it works</a>
                <a href="{{ route('pricing') }}" class="nav-link">Pricing</a>
                <div class="mt-1"><x-currency-switcher align="left"/></div>
                <div class="mt-2 grid grid-cols-2 gap-2">
                    <a href="{{ route('login') }}" class="btn btn-outline">Log in</a>
                    <a href="{{ route('pricing') }}" class="btn btn-white">Buy license</a>
                </div>
            </div>
        </div>
    </header>

    <main>{{ $slot }}</main>

    {{-- Footer --}}
    <footer class="relative mt-32 border-t hairline">
        <div class="mx-auto max-w-7xl px-4 py-16 sm:px-6">
            <div class="grid gap-12 md:grid-cols-[1.4fr_repeat(4,1fr)]">
                <div>
                    <x-logo/>
                    <p class="mt-4 max-w-xs text-sm leading-relaxed text-ink-400">{{ setting('home.footer_blurb') }}</p>
                    <div class="mt-5 space-y-1.5 text-sm">
                        @if ($e = setting('contact.email_sales'))<a href="mailto:{{ $e }}" class="flex items-center gap-2 text-ink-300 hover:text-white"><x-icon name="mail" class="size-3.5 text-ink-500"/>{{ $e }}</a>@endif
                        @if ($p = setting('contact.phone'))<a href="tel:{{ preg_replace('/[^\d+]/', '', $p) }}" class="flex items-center gap-2 text-ink-300 hover:text-white"><x-icon name="headphones" class="size-3.5 text-ink-500"/>{{ $p }}</a>@endif
                    </div>
                    @if ($socials = app(\App\Support\Settings::class)->socials())
                        <div class="mt-5 flex flex-wrap gap-2">
                            @foreach ($socials as $label => $url)
                                <a href="{{ $url }}" target="_blank" rel="noopener" class="rounded-lg border hairline px-2.5 py-1 text-xs text-ink-300 transition hover:border-white/20 hover:text-white">{{ $label }}</a>
                            @endforeach
                        </div>
                    @endif
                    <div class="mt-6 flex items-center gap-2 text-xs text-ink-400">
                        <span class="relative flex size-2"><span class="absolute inline-flex size-full animate-ping rounded-full bg-mint-400 opacity-60"></span><span class="relative inline-flex size-2 rounded-full bg-mint-400"></span></span>
                        License & update servers operational
                    </div>
                </div>
                @foreach ([
                    'LabelDesk' => [['Features', route('home').'#product'], ['White-label', route('home').'#white-label'], ['Pricing', route('pricing')], ['Live demo', '#']],
                    'Customers' => [['Customer portal', route('login')], ['Documentation', '#'], ['Changelog', '#'], ['Support', '#']],
                    'Company' => [['Contact', route('contact')], ['Partners & resellers', route('contact')], ['Contact sales', 'mailto:'.setting('contact.email_sales')]],
                    'Legal' => collect(config('site.pages'))->map(fn ($t, $slug) => [$t, route('legal', $slug)])->values()->all(),
                ] as $head => $links)
                    <div>
                        <div class="eyebrow mb-4">{{ $head }}</div>
                        <ul class="space-y-2.5 text-sm">
                            @foreach ($links as [$l, $href])
                                <li><a href="{{ $href }}" class="text-ink-300 transition hover:text-white">{{ $l }}</a></li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
            <div class="mt-16 flex flex-col items-start justify-between gap-4 border-t hairline pt-8 text-xs text-ink-500 sm:flex-row sm:items-center">
                <span>© {{ date('Y') }} {{ setting('invoice.company_name') }}. We are a software vendor, not a music distributor.</span>
                <span class="flex items-center gap-3"><x-currency-switcher up/><span class="font-mono">Times in {{ $localeTimezone ?? 'UTC' }}</span></span>
            </div>
        </div>
        <div aria-hidden="true" class="pointer-events-none select-none overflow-hidden">
            <div class="mx-auto -mb-[3vw] max-w-7xl px-4 text-center font-semibold leading-none tracking-[-0.06em] text-transparent sm:px-6"
                 style="font-size: clamp(64px, 17vw, 240px); -webkit-text-stroke: 1px rgb(255 255 255 / .07); background: linear-gradient(180deg, rgb(255 255 255 / .06), transparent 75%); -webkit-background-clip: text; background-clip: text;">musicdesk</div>
        </div>
    </footer>
</x-layouts.base>
