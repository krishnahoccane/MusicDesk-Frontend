<x-layouts.marketing>
    {{-- ============================ HERO ============================ --}}
    <section class="relative mx-auto max-w-7xl px-4 pt-14 sm:px-6 sm:pt-20">
        <div class="mx-auto max-w-5xl text-center">
            <a href="{{ route('pricing') }}" class="group inline-flex items-center gap-2 rounded-full glass py-1 pl-1 pr-3 text-xs text-ink-200 transition hover:border-white/20">
                <span class="rounded-full bg-gradient-to-r from-volt-500 to-flare-500 px-2 py-0.5 font-medium text-white">New</span>
                {{ setting('home.announcement') }}
                <x-icon name="arrow-right" class="size-3 transition group-hover:translate-x-0.5"/>
            </a>

            <h1 class="display mt-8 text-[44px] leading-[0.98] sm:text-[64px] lg:text-[84px]">
                {{ setting('home.hero_line1') }}<br class="hidden sm:block">
                <span class="serif-accent text-gradient pr-2">{{ setting('home.hero_accent') }}</span> {{ setting('home.hero_line2') }}
            </h1>
            <p class="mx-auto mt-7 max-w-2xl text-[17px] leading-relaxed text-ink-300 sm:text-lg">
                {{ setting('home.hero_subtitle') }}
            </p>
            <div class="mt-10 flex flex-col items-center justify-center gap-3 sm:flex-row">
                <a href="{{ route('pricing') }}" class="btn btn-white btn-lg w-full sm:w-auto"><x-icon name="key"/> {{ setting('home.cta_primary') }}</a>
                <a href="#white-label" class="btn btn-outline btn-lg w-full sm:w-auto">{{ setting('home.cta_secondary') }} <x-icon name="arrow-right"/></a>
            </div>
            <div class="mt-6 flex flex-wrap items-center justify-center gap-x-5 gap-y-2 text-xs text-ink-400">
                <span class="flex items-center gap-1.5"><x-icon name="check" class="size-3.5 text-mint-400"/> Self-hosted on your server</span>
                <span class="flex items-center gap-1.5"><x-icon name="check" class="size-3.5 text-mint-400"/> No revenue share, ever</span>
                <span class="flex items-center gap-1.5"><x-icon name="check" class="size-3.5 text-mint-400"/> Annual or lifetime license</span>
            </div>
        </div>

        {{-- Live white-label preview --}}
        <div id="white-label" class="relative mx-auto mt-20 max-w-6xl scroll-mt-28"
             x-data="{
                brands: [
                    { name: 'Lowtide', domain: 'distribution.lowtide.co', color: '#7c5cff' },
                    { name: 'Sunset Records', domain: 'portal.sunsetrecords.com', color: '#ff8a3d' },
                    { name: 'Northwave', domain: 'artists.northwave.io', color: '#22d9a0' },
                    { name: 'Kumo Music', domain: 'royalties.kumo.jp', color: '#ff3fd2' },
                ],
                i: 0, name: 'Lowtide', domain: 'distribution.lowtide.co', color: '#7c5cff',
                pick(n) { this.i = n; Object.assign(this, { name: this.brands[n].name, domain: this.brands[n].domain, color: this.brands[n].color }) }
             }">
            <div class="absolute -inset-x-0 -top-10 bottom-0 rounded-[40px] opacity-50 blur-3xl transition-colors duration-700 sm:-inset-x-10"
                 :style="`background: radial-gradient(60% 50% at 50% 30%, ${color}66, transparent 70%)`"></div>

            {{-- Brand controls --}}
            <div class="relative mx-auto mb-5 flex max-w-3xl flex-col items-center gap-3 rounded-2xl glass p-2 sm:flex-row">
                <div class="flex w-full gap-1 overflow-x-auto sm:w-auto">
                    <template x-for="(b, n) in brands" :key="b.name">
                        <button @click="pick(n)" class="flex shrink-0 items-center gap-2 rounded-xl px-3 py-2 text-xs font-medium transition"
                                :class="i === n ? 'bg-white/10 text-white' : 'text-ink-400 hover:text-white'">
                            <span class="size-2.5 rounded-full" :style="`background: ${b.color}`"></span><span x-text="b.name"></span>
                        </button>
                    </template>
                </div>
                <div class="flex w-full items-center gap-2 sm:ml-auto sm:w-auto">
                    <input x-model="name" @input="i = -1" maxlength="18" class="input !py-1.5 text-xs sm:!w-40" placeholder="Your brand name" aria-label="Brand name">
                    <label class="relative grid size-8 shrink-0 cursor-pointer place-items-center overflow-hidden rounded-lg ring-1 ring-white/15" :style="`background: ${color}`" title="Pick brand color">
                        <input type="color" x-model="color" @input="i = -1" class="absolute inset-0 cursor-pointer opacity-0" aria-label="Brand color">
                    </label>
                </div>
            </div>
            <p class="relative mb-4 text-center text-xs text-ink-500">Try it: type your brand name and pick a color. This is LabelDesk running under your name.</p>

            <div class="glow-border relative overflow-hidden rounded-[28px] border border-white/10 bg-ink-900/80 p-2 shadow-[0_40px_120px_-30px_rgb(0_0_0/.9)] backdrop-blur-xl">
                <div class="flex items-center gap-1.5 px-3 py-2">
                    <span class="size-2.5 rounded-full bg-white/10"></span><span class="size-2.5 rounded-full bg-white/10"></span><span class="size-2.5 rounded-full bg-white/10"></span>
                    <span class="mx-auto flex items-center gap-1.5 rounded-md bg-white/[0.04] px-3 py-0.5 font-mono text-[10px] text-ink-400"><x-icon name="lock" class="size-2.5"/><span x-text="i >= 0 ? domain : (name.toLowerCase().replace(/[^a-z0-9]+/g, '') || 'yourbrand') + '.com'"></span></span>
                </div>
                <div class="grid overflow-hidden rounded-[22px] bg-[#0c0c12] text-[12px] md:grid-cols-[200px_1fr]">
                    {{-- tenant sidebar --}}
                    <div class="hidden border-r border-white/[0.06] p-4 md:block">
                        <div class="flex items-center gap-2.5">
                            <span class="grid size-8 place-items-center rounded-lg text-sm font-bold text-white transition-colors duration-500" :style="`background: ${color}`" x-text="(name || 'Y').charAt(0).toUpperCase()"></span>
                            <span class="truncate text-sm font-semibold text-white" x-text="name || 'Your brand'"></span>
                        </div>
                        <div class="mt-6 space-y-0.5">
                            @foreach ([['Dashboard', 'grid', false], ['Releases', 'disc', false], ['Royalties', 'wallet', true], ['Artists', 'users', false], ['Contracts', 'file', false], ['Delivery', 'upload', false]] as [$l, $ic, $a])
                                <div class="flex items-center gap-2 rounded-lg px-2.5 py-2 {{ $a ? 'text-white' : 'text-ink-400' }}" @if ($a) :style="`background: ${color}22; color: ${color}`" @endif>
                                    <x-icon :name="$ic" class="size-3.5"/>{{ $l }}
                                </div>
                            @endforeach
                        </div>
                    </div>
                    {{-- tenant main --}}
                    <div class="p-4 sm:p-6">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div><div class="text-[11px] text-ink-500">Royalties</div><div class="text-lg font-semibold text-white">Q3 2026 statements</div></div>
                            <span class="rounded-lg px-3 py-1.5 text-[11px] font-medium text-white transition-colors duration-500" :style="`background: ${color}`">Send all statements</span>
                        </div>
                        <div class="mt-4 grid grid-cols-3 gap-2">
                            @foreach ([['Gross revenue', '$184,220', '+18%'], ['Recouped', '$41,908', ''], ['Payable to artists', '$96,310', '']] as [$k, $v, $d])
                                <div class="rounded-xl border border-white/[0.06] bg-white/[0.02] p-3">
                                    <div class="text-[10px] text-ink-500 sm:text-[11px]">{{ $k }}</div>
                                    <div class="mt-0.5 text-sm font-semibold text-white sm:text-base">{{ $v }}</div>
                                    @if ($d)<div class="text-[10px]" :style="`color: ${color}`">{{ $d }} vs Q2</div>@endif
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-3 grid gap-3 lg:grid-cols-[1fr_220px]">
                            <div class="overflow-hidden rounded-xl border border-white/[0.06]">
                                @foreach ([['Nova Reyes', '$32,410', 'Ready'], ['Kai Moreno', '$18,902', 'Ready'], ['Glass Coast (V/A)', '$12,177', 'Recouping'], ['Mira & The Tide', '$9,640', 'Ready'], ['Halogen', '$4,113', 'Needs review']] as [$a, $amt, $st])
                                    <div class="flex items-center justify-between border-b border-white/[0.04] px-3 py-2.5 last:border-0">
                                        <span class="flex items-center gap-2 text-ink-200"><span class="size-5 rounded-full bg-white/10"></span>{{ $a }}</span>
                                        <span class="flex items-center gap-3"><span class="font-mono text-ink-300">{{ $amt }}</span>
                                            <span class="hidden rounded-full px-2 py-0.5 text-[10px] sm:inline" :style="`background: ${color}1f; color: ${color}`">{{ $st }}</span></span>
                                    </div>
                                @endforeach
                            </div>
                            <div class="hidden rounded-xl border border-white/[0.06] p-3 lg:block">
                                <div class="text-[11px] text-ink-500">Revenue by store</div>
                                <div class="mt-3 flex h-28 items-end gap-1.5">
                                    @foreach ([46, 72, 38, 88, 60, 95, 70] as $n => $h)
                                        <div class="flex-1 rounded-t transition-colors duration-500" :style="`height: {{ $h }}%; background: ${color}{{ $n === 5 ? '' : '55' }}`"></div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                        <div class="mt-4 text-center text-[10px] text-ink-600">© <span x-text="name || 'Your brand'"></span>. All rights reserved.</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ============================ NOT A DISTRIBUTOR ============================ --}}
    <section class="mx-auto mt-28 max-w-7xl px-4 sm:px-6">
        <div class="reveal grid gap-px overflow-hidden rounded-3xl border hairline bg-white/[0.06] md:grid-cols-3">
            @foreach ([
                ['wallet', 'No revenue share', 'We charge for the software license, never a cut of your royalties or your artists’ earnings.'],
                ['lock', 'Your servers, your data', 'LabelDesk runs on your own infrastructure. Your catalog and customer data never touch our servers.'],
                ['users', 'Your customers', 'Your artists only ever see your brand. We never contact them, market to them or appear in the product.'],
            ] as [$ic, $h, $p])
                <div class="bg-ink-950 p-8">
                    <span class="grid size-10 place-items-center rounded-xl bg-white/[0.04] text-volt-300 ring-1 ring-white/[0.06]"><x-icon :name="$ic" class="size-5"/></span>
                    <h3 class="mt-5 text-lg font-semibold text-white">{{ $h }}</h3>
                    <p class="mt-2 text-sm leading-relaxed text-ink-400">{{ $p }}</p>
                </div>
            @endforeach
        </div>
        <p class="reveal mt-5 text-center text-xs text-ink-500">MusicDesk is a software vendor, not a music distributor. You keep your own distribution agreements, artist contracts and payouts.</p>
    </section>

    {{-- ============================ PRODUCT BENTO ============================ --}}
    <section id="product" class="mx-auto mt-32 max-w-7xl scroll-mt-24 px-4 sm:px-6">
        <div class="reveal max-w-2xl">
            <div class="eyebrow">What’s inside LabelDesk</div>
            <h2 class="display mt-4 text-4xl sm:text-5xl">{{ setting('home.product_heading') }} <span class="serif-accent text-gradient">{{ setting('home.product_accent') }}</span></h2>
            <p class="mt-5 text-lg text-ink-400">Everything a label or distributor needs to serve artists. Launch in days instead of spending a year building it.</p>
        </div>

        <div class="mt-14 grid gap-4 md:grid-cols-6">
            <div class="reveal card group relative overflow-hidden p-7 md:col-span-4">
                <div class="flex items-center gap-2 text-volt-300"><x-icon name="disc" class="size-5"/><span class="eyebrow !text-volt-300">Releases & delivery</span></div>
                <h3 class="mt-4 text-2xl font-semibold tracking-tight text-white">Take in releases, check them and deliver to stores.</h3>
                <p class="mt-2 max-w-md text-ink-400">Artists upload through your branded portal. Your team reviews metadata and artwork, then delivers through your own DSP agreements by DDEX or FTP.</p>
                <div class="mt-8 flex h-28 items-end gap-[3px]">
                    @for ($i = 0; $i < 90; $i++)
                        @php $h = 18 + abs(sin($i * 0.37) * 60 + cos($i * 0.11) * 22); @endphp
                        <span class="flex-1 rounded-full bg-gradient-to-t from-volt-600/40 to-volt-300/90 transition-all duration-500 group-hover:from-flare-500/50 group-hover:to-amber-glow" style="height: {{ min($h, 100) }}%; transition-delay: {{ $i * 6 }}ms"></span>
                    @endfor
                </div>
            </div>
            <div class="reveal card relative overflow-hidden p-7 md:col-span-2">
                <div class="flex items-center gap-2 text-flare-400"><x-icon name="receipt" class="size-5"/><span class="eyebrow !text-flare-400">Royalty accounting</span></div>
                <h3 class="mt-4 text-xl font-semibold tracking-tight text-white">Statements in minutes, not weeks.</h3>
                <p class="mt-2 text-sm text-ink-400">Import store reports, apply contract rates and recoupment, then publish statements to every artist.</p>
                <div class="mt-6 flex justify-center">
                    <x-chart.donut :size="140" value="1-click" label="statements" :data="[['share' => 50, 'color' => '#7c5cff'], ['share' => 30, 'color' => '#ff3fd2'], ['share' => 20, 'color' => '#ffb547']]"/>
                </div>
            </div>
            @foreach ([
                ['users', 'Artist portal', 'Artists log in on your domain to upload releases, see their earnings and download statements.', 'text-sky-glow'],
                ['split', 'Splits & contracts', 'Store deal terms, advances and collaborator splits. LabelDesk applies them to every sale.', 'text-amber-glow'],
                ['link', 'API & webhooks', 'Connect your accounting, CRM or payout provider with a REST API and webhooks.', 'text-mint-400'],
            ] as [$ic, $h, $p, $c])
                <div class="reveal card p-7 md:col-span-2">
                    <div class="flex items-center gap-2 {{ $c }}"><x-icon :name="$ic" class="size-5"/><span class="eyebrow !text-current">{{ $h }}</span></div>
                    <p class="mt-4 text-[15px] leading-relaxed text-ink-300">{{ $p }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- ============================ WHITE-LABEL CHECKLIST ============================ --}}
    <section class="mx-auto mt-32 max-w-7xl px-4 sm:px-6">
        <div class="reveal relative overflow-hidden rounded-[32px] border border-white/10 p-8 sm:p-14"
             style="background: radial-gradient(80% 120% at 100% 0%, rgb(255 63 210 / .16), transparent 55%), radial-gradient(70% 100% at 0% 100%, rgb(124 92 255 / .22), transparent 60%), #0b0b12;">
            <div class="absolute inset-0 grid-bg opacity-60"></div>
            <div class="relative grid items-center gap-12 lg:grid-cols-2">
                <div>
                    <div class="eyebrow">Truly white-label</div>
                    <h2 class="display mt-4 text-4xl sm:text-5xl">{{ setting('home.whitelabel_heading') }} <span class="serif-accent text-gradient">{{ setting('home.whitelabel_accent') }}</span></h2>
                    <p class="mt-5 max-w-lg text-lg text-ink-300">No “Powered by” badges and no hidden links back to us. Your artists will think you built it.</p>
                    <ul class="mt-8 grid gap-3 text-sm text-ink-200 sm:grid-cols-2">
                        @foreach (['Your domain & SSL', 'Logo, favicon & app icon', 'Brand colors & fonts', 'Branded emails & sender', 'Branded PDF statements', 'Custom login page', 'Your terms & privacy pages', 'Installable mobile web app'] as $f)
                            <li class="flex items-center gap-2.5"><span class="grid size-5 place-items-center rounded-full bg-volt-500/20 text-volt-300"><x-icon name="check" class="size-3" stroke="2.5"/></span>{{ $f }}</li>
                        @endforeach
                    </ul>
                </div>
                {{-- License card visual --}}
                <div class="relative mx-auto w-full max-w-md [perspective:1200px]">
                    <div class="absolute -inset-6 rounded-[40px] bg-volt-500/20 blur-3xl"></div>
                    <div class="relative rotate-[-4deg] transition duration-700 [transform-style:preserve-3d] hover:rotate-0 hover:[transform:rotateY(-8deg)_rotateX(6deg)]">
                        <div class="relative overflow-hidden rounded-3xl border border-white/15 p-7 shadow-2xl shadow-black/60"
                             style="background: linear-gradient(135deg, rgb(124 92 255 / .55), rgb(255 63 210 / .35) 55%, rgb(255 181 71 / .3)), #14121f;">
                            <div class="absolute inset-0 opacity-30" style="background: repeating-linear-gradient(115deg, transparent 0 18px, rgb(255 255 255 / .06) 18px 19px)"></div>
                            <div class="relative flex items-start justify-between">
                                <x-logo product="LabelDesk" size="sm"/>
                                <span class="rounded-full bg-black/30 px-2.5 py-1 font-mono text-[10px] text-white backdrop-blur">BUSINESS · 3 DOMAINS</span>
                            </div>
                            <div class="relative mt-14 font-mono text-lg tracking-[0.18em] text-white sm:text-xl">LDSK-7F3K-Q9PX-2MWA</div>
                            <div class="relative mt-6 flex items-end justify-between text-xs">
                                <div><div class="text-white/60">Licensed to</div><div class="mt-0.5 font-medium text-white">Lowtide Distribution</div></div>
                                <div class="text-right"><div class="text-white/60">Renews</div><div class="mt-0.5 font-medium text-white">03 / 27</div></div>
                            </div>
                        </div>
                    </div>
                    <div class="relative mx-6 -mt-4 rounded-b-2xl glass px-5 pb-4 pt-7 text-xs">
                        <div class="flex justify-between text-ink-300"><span>Domains activated</span><span class="font-mono text-white">3 / 4</span></div>
                        <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-white/10"><div class="h-full w-3/4 rounded-full bg-gradient-to-r from-volt-500 to-flare-500"></div></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ============================ HOW IT WORKS ============================ --}}
    <section id="how" class="mx-auto mt-32 max-w-7xl scroll-mt-24 px-4 sm:px-6">
        <div class="reveal max-w-2xl">
            <div class="eyebrow">How it works</div>
            <h2 class="display mt-4 text-4xl sm:text-5xl">From license to launch <span class="serif-accent text-gradient">in a week.</span></h2>
        </div>
        <div class="mt-14 grid gap-4 md:grid-cols-4">
            @foreach ([
                ['01', 'key', 'Buy a license', 'Choose a tier, annual or lifetime, and how many domains you need. Your key appears in your customer portal right away.'],
                ['02', 'download', 'Install', 'Download the package and deploy it to your server with our installer. Or add our installation service and we set it up.'],
                ['03', 'sparkles', 'Brand it', 'Add your logo, colors, domain and email templates in the theme editor. You can preview everything before you go live.'],
                ['04', 'refresh', 'Launch & update', 'Invite your artists. New versions show up in your portal and install with one click from your admin panel.'],
            ] as [$n, $ic, $h, $p])
                <div class="reveal relative rounded-3xl border hairline p-6">
                    <div class="flex items-center justify-between">
                        <span class="font-mono text-sm text-volt-300">{{ $n }}</span>
                        <x-icon :name="$ic" class="size-5 text-ink-500"/>
                    </div>
                    <h3 class="mt-6 text-lg font-semibold text-white">{{ $h }}</h3>
                    <p class="mt-2 text-sm leading-relaxed text-ink-400">{{ $p }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- ============================ TESTIMONIALS ============================ --}}
    <section class="mx-auto mt-32 max-w-7xl px-4 sm:px-6">
        <div class="reveal text-center">
            <div class="eyebrow">Customers</div>
            <h2 class="display mt-4 text-4xl sm:text-5xl">Running on LabelDesk, <span class="serif-accent text-gradient">branded as their own.</span></h2>
        </div>
        <div class="mt-14 columns-1 gap-4 space-y-4 md:columns-3">
            @foreach ([
                ['We launched our own distribution service in nine days. Our artists have no idea there’s a vendor behind it, and that’s exactly what we wanted.', 'Emma Clarke', 'Founder, Northlight Distribution', 'from-volt-500 to-sky-glow'],
                ['Building this in-house was quoted at 14 months. The LabelDesk license paid for itself in the first royalty cycle.', 'Aarav Mehta', 'CTO, Bassline India', 'from-flare-500 to-volt-500'],
                ['Three sub-labels, three brands, one Business license. Adding a domain from the portal takes two minutes.', 'Hana Sato', 'Operations, Kumo Music Group', 'from-mint-500 to-sky-glow'],
                ['Updates are painless. We get the new version in the portal, test it on staging, then roll it out.', 'Theo Park', 'Lead engineer, Lowtide', 'from-amber-glow to-coral-500'],
                ['No revenue share was the deciding factor. We pay for software, not a slice of our artists’ income.', 'Dante Okoro', 'MD, Lagoswave', 'from-sky-glow to-volt-400'],
                ['Statements used to take our finance team two weeks. Now it takes an afternoon.', 'Sofia Bianchi', 'Finance, Notte Records', 'from-coral-500 to-flare-500'],
            ] as [$q, $n, $r, $g])
                <figure class="reveal card break-inside-avoid p-6">
                    <div class="flex gap-0.5 text-amber-glow">@for ($s = 0; $s < 5; $s++)<x-icon name="star" class="size-3.5"/>@endfor</div>
                    <blockquote class="mt-4 text-[15px] leading-relaxed text-ink-100">“{{ $q }}”</blockquote>
                    <figcaption class="mt-5 flex items-center gap-3">
                        <span class="size-9 rounded-full bg-gradient-to-br {{ $g }}"></span>
                        <span><span class="block text-sm font-medium text-white">{{ $n }}</span><span class="text-xs text-ink-400">{{ $r }}</span></span>
                    </figcaption>
                </figure>
            @endforeach
        </div>
    </section>

    {{-- ============================ PRICING ============================ --}}
    <section class="mx-auto mt-32 max-w-7xl px-4 sm:px-6">
        <div class="reveal mb-10 flex flex-col items-end justify-between gap-6 sm:flex-row">
            <div>
                <div class="eyebrow">Licensing</div>
                <h2 class="display mt-4 text-4xl sm:text-5xl">{{ setting('home.pricing_heading') }} <span class="serif-accent text-gradient">{{ setting('home.pricing_accent') }}</span></h2>
            </div>
            <a href="{{ route('pricing') }}" class="btn btn-outline">Full pricing & add-ons <x-icon name="arrow-right"/></a>
        </div>
        @include('marketing.partials.license-tiers', ['plans' => $plans])
    </section>

    {{-- ============================ CTA ============================ --}}
    <section class="mx-auto mt-32 max-w-5xl px-4 sm:px-6">
        <div class="reveal relative overflow-hidden rounded-[32px] border border-white/10 px-6 py-16 text-center sm:px-16 sm:py-20">
            <div class="absolute inset-0" style="background: radial-gradient(60% 80% at 50% 0%, rgb(124 92 255 / .35), transparent 70%), radial-gradient(40% 60% at 80% 100%, rgb(255 63 210 / .2), transparent 70%)"></div>
            <div class="relative">
                <div class="mx-auto flex h-10 w-fit items-end gap-1">
                    @foreach ([0, .2, .5, .1, .7, .3, .6, .15, .4] as $dl)
                        <span class="eq-bar !w-1 h-full bg-gradient-to-t from-volt-500 to-flare-400" style="animation-delay: -{{ $dl }}s"></span>
                    @endforeach
                </div>
                <h2 class="display mt-8 text-4xl sm:text-6xl">{{ setting('home.cta_heading') }} <span class="serif-accent text-gradient">{{ setting('home.cta_accent') }}</span></h2>
                <p class="mx-auto mt-5 max-w-xl text-lg text-ink-300">Licenses start at {{ money($plans->min('price_annual') ?? 0)['display'] }} a year. {{ setting('home.cta_text') }}</p>
                <div class="mt-10 flex flex-col justify-center gap-3 sm:flex-row">
                    <a href="{{ route('pricing') }}" class="btn btn-white btn-lg">Buy a license</a>
                    <a href="#" class="btn btn-outline btn-lg">Book a live demo</a>
                </div>
            </div>
        </div>
    </section>
</x-layouts.marketing>
