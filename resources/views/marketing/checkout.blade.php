@php
    $cs = app(\App\Services\Currency\CurrencyService::class);
    $user = auth()->user();
    $fmt = fn ($v) => $cs->format($v, $quote['currency'], true);
    $countries = ['US' => 'United States', 'IN' => 'India', 'GB' => 'United Kingdom', 'DE' => 'Germany', 'FR' => 'France', 'IT' => 'Italy', 'ES' => 'Spain', 'NL' => 'Netherlands', 'IE' => 'Ireland', 'CA' => 'Canada', 'AU' => 'Australia', 'NZ' => 'New Zealand', 'SG' => 'Singapore', 'AE' => 'United Arab Emirates', 'JP' => 'Japan', 'BR' => 'Brazil', 'MX' => 'Mexico', 'ZA' => 'South Africa', 'NG' => 'Nigeria'];
    $country = old('country', $org?->country ?? (app()->bound('locale.country') ? app('locale.country') : null) ?? 'US');
    $gatewayIcons = ['razorpay' => 'zap', 'stripe' => 'card', 'paypal' => 'wallet', 'bank_transfer' => 'receipt'];
@endphp
<x-layouts.marketing title="Checkout">
    <section class="mx-auto max-w-6xl px-4 pt-10 sm:px-6"
             x-data="{
                billing: @js(old('billing', $billing)), domains: {{ (int) old('domains', $domains) }}, addons: @js(old('addons', [])),
                country: @js($country), vat_id: @js(old('vat_id', $org?->vat_id ?? '')), coupon: @js(old('coupon', '')),
                gateway: @js(old('gateway', $gateways->first()?->code())), processing: false,
                q: { lines: {{ json_encode(array_map(fn ($l) => ['description' => $l['description'], 'quantity' => $l['quantity'], 'amount' => $fmt($l['amount'])], $quote['lines'])) }},
                     subtotal: @js($fmt($quote['subtotal'])), discount: null, tax: @js($fmt($quote['tax'])), tax_rate: {{ round($quote['tax_rate'] * 100, 2) }}, total: @js($fmt($quote['total'])), coupon: null },
                timer: null,
                requote() {
                    clearTimeout(this.timer);
                    this.timer = setTimeout(async () => {
                        const r = await fetch(@js(route('checkout.quote', $plan->code)), { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                            body: JSON.stringify({ billing: this.billing, domains: this.domains, addons: this.addons, country: this.country, vat_id: this.vat_id, coupon: this.coupon }) });
                        if (r.ok) { this.q = await r.json(); if (this.q.coupon_invalid) toast('That coupon code isn’t valid', 'danger'); }
                    }, 250);
                },
             }"
             x-init="requote(); $watch('billing', () => requote()); $watch('domains', () => requote()); $watch('addons', () => requote()); $watch('country', () => requote())">
        <a href="{{ route('pricing') }}" class="inline-flex items-center gap-1.5 text-sm text-ink-400 hover:text-white">
            <x-icon name="chevron-right" class="size-4 rotate-180"/> Back to pricing
        </a>

        <ol class="mt-6 flex items-center gap-3 text-xs">
            @foreach (['Choose license', 'Organisation & payment', 'Get your key'] as $i => $s)
                <li class="flex items-center gap-2 {{ $i <= 1 ? 'text-white' : 'text-ink-500' }}">
                    <span class="grid size-6 place-items-center rounded-full font-mono text-[10px] {{ $i === 0 ? 'bg-mint-500/20 text-mint-400' : ($i === 1 ? 'bg-volt-500 text-white' : 'bg-white/5') }}">
                        @if ($i === 0) <x-icon name="check" class="size-3" stroke="3"/> @else {{ $i + 1 }} @endif
                    </span>
                    <span class="hidden sm:inline">{{ $s }}</span>
                </li>
                @if ($i < 2)<li class="h-px w-8 bg-white/10 sm:w-14"></li>@endif
            @endforeach
        </ol>

        <form method="POST" action="{{ route('checkout.store', $plan->code) }}" @submit="processing = true" class="mt-10 grid gap-8 lg:grid-cols-[1fr_400px]">
            @csrf
            <input type="hidden" name="billing" :value="billing">
            <input type="hidden" name="domains" :value="domains">

            <div class="space-y-6">
                {{-- License options --}}
                <div class="card card-pad">
                    <h2 class="text-lg font-semibold text-white">{{ setting('branding.product_name') }} {{ $plan->name }}</h2>
                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <label class="cursor-pointer rounded-xl border p-4 transition" :class="billing === 'annual' ? 'border-volt-500/60 bg-volt-500/10' : 'border-white/[0.08] hover:border-white/15'">
                            <input type="radio" value="annual" x-model="billing" class="sr-only">
                            <span class="block text-sm font-medium text-white">Annual license</span>
                            <span class="text-xs text-ink-400">{{ money($plan->price_annual)['display'] }} / year · renews yearly</span>
                        </label>
                        @if ($plan->price_lifetime)
                            <label class="cursor-pointer rounded-xl border p-4 transition" :class="billing === 'lifetime' ? 'border-volt-500/60 bg-volt-500/10' : 'border-white/[0.08] hover:border-white/15'">
                                <input type="radio" value="lifetime" x-model="billing" class="sr-only">
                                <span class="block text-sm font-medium text-white">Lifetime license</span>
                                <span class="text-xs text-ink-400">{{ money($plan->price_lifetime)['display'] }} once · 12 months of updates</span>
                            </label>
                        @endif
                    </div>
                    <div class="mt-3 flex items-center justify-between rounded-xl border hairline bg-white/[0.02] px-4 py-3">
                        <div class="text-sm"><div class="text-ink-100">Production domains</div><div class="text-xs text-ink-500">{{ $plan->domains }} included · staging domains are free</div></div>
                        <div class="flex items-center gap-1">
                            <button type="button" @click="domains = Math.max(0, domains - 1)" class="grid size-8 place-items-center rounded-lg bg-white/[0.06] hover:bg-white/10" aria-label="Fewer domains">−</button>
                            <span class="w-16 text-center font-mono text-sm text-white" x-text="'{{ $plan->domains }} + ' + domains"></span>
                            <button type="button" @click="domains++" class="grid size-8 place-items-center rounded-lg bg-white/[0.06] hover:bg-white/10" aria-label="More domains">+</button>
                        </div>
                    </div>
                </div>

                {{-- Organisation --}}
                <div class="card card-pad">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-semibold text-white">Organisation</h2>
                            @guest<p class="text-sm text-ink-400">Already a customer? <a href="{{ route('login') }}" class="text-volt-300 hover:text-white">Log in</a> to add this license to your account.</p>@endguest
                            @auth<p class="text-sm text-ink-400">Buying as {{ $user->name }} ({{ $user->email }})</p>@endauth
                        </div>
                    </div>
                    <div class="mt-5 grid gap-4 sm:grid-cols-2">
                        @guest
                            <div><label class="label" for="name">Your name</label><input id="name" name="name" value="{{ old('name') }}" class="input" autocomplete="name" required>@error('name')<p class="mt-1 text-xs text-coral-400">{{ $message }}</p>@enderror</div>
                            <div><label class="label" for="email">Work email</label><input id="email" type="email" name="email" value="{{ old('email') }}" class="input" autocomplete="email" required>@error('email')<p class="mt-1 text-xs text-coral-400">{{ $message }}</p>@enderror</div>
                            <div class="sm:col-span-2"><label class="label" for="password">Create a password</label><input id="password" type="password" name="password" class="input" autocomplete="new-password" minlength="10" required>
                                <p class="mt-1 text-xs text-ink-500">For your customer portal, where your license key, downloads and invoices live.</p>@error('password')<p class="mt-1 text-xs text-coral-400">{{ $message }}</p>@enderror</div>
                        @endguest
                        <div class="sm:col-span-2"><label class="label" for="company">Organisation (licensed to)</label><input id="company" name="company" value="{{ old('company', $org?->name) }}" class="input" autocomplete="organization" required>
                            <p class="mt-1 text-xs text-ink-500">Appears on your license certificate and invoices. Your artists never see it.</p></div>
                        <div><label class="label" for="country">Country</label>
                            <select id="country" name="country" x-model="country" class="input">
                                @foreach ($countries as $cc => $cn)<option value="{{ $cc }}">{{ $cn }}</option>@endforeach
                            </select></div>
                        <div><label class="label" for="vat_id">VAT / GST ID <span class="text-ink-500">(optional)</span></label><input id="vat_id" name="vat_id" x-model="vat_id" @change="requote()" class="input" placeholder="e.g. GB123456789"></div>
                        <div class="sm:col-span-2"><label class="label" for="primary_domain">Primary domain <span class="text-ink-500">(optional, you can add it later)</span></label>
                            <div class="flex"><span class="grid place-items-center rounded-l-xl border border-r-0 border-white/[0.08] bg-white/[0.03] px-3 text-sm text-ink-500">https://</span><input id="primary_domain" name="primary_domain" value="{{ old('primary_domain') }}" class="input !rounded-l-none" placeholder="portal.yourlabel.com"></div>
                            @error('primary_domain')<p class="mt-1 text-xs text-coral-400">{{ $message }}</p>@enderror</div>
                    </div>
                </div>

                {{-- Add-ons --}}
                @if ($addons->isNotEmpty())
                    <div class="card card-pad">
                        <h2 class="text-lg font-semibold text-white">Add-ons</h2>
                        <div class="mt-4 space-y-2">
                            @foreach ($addons as $a)
                                <label class="flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition" :class="addons.includes('{{ $a->code }}') ? 'border-volt-500/50 bg-volt-500/[0.06]' : 'border-white/[0.08] hover:border-white/15'">
                                    <input type="checkbox" name="addons[]" value="{{ $a->code }}" x-model="addons" class="mt-0.5 size-4 accent-volt-500">
                                    <span class="flex-1"><span class="block text-sm font-medium text-white">{{ $a->name }}</span><span class="text-xs text-ink-400">{{ $a->description }}</span></span>
                                    <span class="font-mono text-sm text-ink-100">+{{ money($a->price)['display'] }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Payment --}}
                <div class="card card-pad">
                    <h2 class="text-lg font-semibold text-white">Payment method</h2>
                    @if ($gateways->isEmpty())
                        <p class="mt-3 rounded-xl border border-amber-glow/25 bg-amber-glow/[0.06] p-4 text-sm text-amber-glow">No payment method is available for {{ $currency }} right now. Switch currency, or <a href="{{ route('contact') }}" class="underline">contact sales</a>.</p>
                    @else
                        <div class="mt-5 grid gap-2 sm:grid-cols-{{ min(4, $gateways->count()) }}">
                            @foreach ($gateways as $g)
                                <label class="flex cursor-pointer flex-col items-center gap-1.5 rounded-xl border px-3 py-3.5 text-sm transition"
                                       :class="gateway === '{{ $g->code() }}' ? 'border-volt-500/60 bg-volt-500/10 text-white ring-4 ring-volt-500/10' : 'border-white/[0.08] text-ink-300 hover:border-white/20'">
                                    <input type="radio" name="gateway" value="{{ $g->code() }}" x-model="gateway" class="sr-only">
                                    <x-icon :name="$gatewayIcons[$g->code()] ?? 'card'" class="size-5"/>{{ $g->name() }}
                                </label>
                            @endforeach
                        </div>
                        <p class="mt-4 text-xs text-ink-500" x-show="gateway === 'razorpay'">Pay with UPI, cards, net banking or wallets in a secure Razorpay window.</p>
                        <p class="mt-4 text-xs text-ink-500" x-show="gateway === 'stripe'" x-cloak>You’ll be sent to Stripe’s secure checkout to pay by card, Apple Pay or Google Pay.</p>
                        <p class="mt-4 text-xs text-ink-500" x-show="gateway === 'paypal'" x-cloak>You’ll be sent to PayPal to approve the payment.</p>
                        <p class="mt-4 text-xs text-ink-500" x-show="gateway === 'bank_transfer'" x-cloak>We’ll issue an invoice with our bank details, due in {{ setting('invoice.due_days') }} days. Your license key works straight away during that period.</p>
                    @endif
                    <p class="mt-3 text-xs text-ink-500">Card details are entered on the payment provider’s page and never reach our servers.</p>
                </div>

                <label class="flex items-start gap-3 text-sm text-ink-400">
                    <input type="checkbox" name="terms" value="1" class="mt-0.5 size-4 accent-volt-500" @checked(old('terms')) required>
                    <span>I agree to the <a href="{{ route('legal', 'terms') }}" target="_blank" class="text-ink-200 underline decoration-white/20 underline-offset-2">Terms</a>, <a href="{{ route('legal', 'refund') }}" target="_blank" class="text-ink-200 underline decoration-white/20 underline-offset-2">Refund Policy</a> and <a href="{{ route('legal', 'license-agreement') }}" target="_blank" class="text-ink-200 underline decoration-white/20 underline-offset-2">License Agreement</a>.</span>
                </label>

                <button class="btn btn-primary btn-lg w-full" :disabled="processing" @disabled($gateways->isEmpty())>
                    <span x-show="!processing" class="flex items-center gap-2"><x-icon name="lock" class="size-4"/> <span x-text="gateway === 'bank_transfer' ? 'Place order · ' + q.total : 'Pay ' + q.total"></span></span>
                    <span x-show="processing" x-cloak class="flex items-center gap-2"><svg class="size-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".25" stroke-width="3"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg> Processing…</span>
                </button>
                <p class="flex items-center justify-center gap-2 text-xs text-ink-500"><x-icon name="shield" class="size-3.5"/> 30-day money-back guarantee.</p>
            </div>

            {{-- Summary --}}
            <aside class="lg:sticky lg:top-24 lg:self-start">
                <div class="glow-border relative overflow-hidden rounded-3xl border border-transparent bg-ink-900/80 p-6 backdrop-blur-xl">
                    <div class="absolute -right-16 -top-16 size-48 rounded-full bg-volt-500/25 blur-3xl"></div>
                    <div class="relative">
                        <div class="flex items-center justify-between"><span class="eyebrow">Order summary</span><x-currency-switcher/></div>
                        <dl class="mt-5 space-y-3 text-sm">
                            <template x-for="l in q.lines" :key="l.description">
                                <div class="flex justify-between gap-4"><dt class="text-ink-300"><span x-text="l.description"></span><span x-show="l.quantity > 1" class="text-ink-500" x-text="' × ' + l.quantity"></span></dt><dd class="shrink-0 font-mono text-ink-100" x-text="l.amount"></dd></div>
                            </template>
                        </dl>
                        <dl class="mt-4 space-y-2.5 border-t hairline pt-4 text-sm">
                            <div class="flex justify-between"><dt class="text-ink-400">Subtotal</dt><dd class="font-mono text-ink-100" x-text="q.subtotal"></dd></div>
                            <div class="flex justify-between" x-show="q.discount" x-cloak><dt class="text-mint-400">Coupon <span x-text="q.coupon"></span></dt><dd class="font-mono text-mint-400" x-text="q.discount"></dd></div>
                            <div class="flex justify-between"><dt class="text-ink-400">Tax <span x-show="q.tax_rate > 0" x-text="'(' + q.tax_rate + '%)'"></span></dt><dd class="font-mono text-ink-100" x-text="q.tax"></dd></div>
                        </dl>
                        <div class="mt-4 flex gap-2">
                            <input name="coupon" x-model="coupon" class="input !py-2 text-sm uppercase" placeholder="Coupon code" @keydown.enter.prevent="requote()">
                            <button type="button" class="btn btn-outline btn-sm" @click="requote()">Apply</button>
                        </div>
                        <div class="mt-5 flex items-end justify-between border-t hairline pt-5">
                            <span class="text-sm text-ink-300">Due today</span>
                            <span class="text-3xl font-semibold tracking-tight text-white" x-text="q.total"></span>
                        </div>
                        <p class="mt-2 text-right text-xs text-ink-500" x-show="billing === 'annual'">Renews yearly. You can turn off auto-renew in your portal.</p>
                        @if ($currency !== 'USD')
                            <p class="mt-1 text-right text-[11px] text-ink-500">Charged in {{ $currency }}. List prices are in USD.</p>
                        @endif
                    </div>
                </div>
                <div class="mt-4 rounded-2xl border hairline p-5 text-sm">
                    <div class="font-medium text-white">What happens next</div>
                    <ul class="mt-3 space-y-2.5 text-ink-400">
                        <li class="flex gap-2.5"><x-icon name="receipt" class="mt-0.5 text-volt-300"/> We email your invoice</li>
                        <li class="flex gap-2.5"><x-icon name="key" class="mt-0.5 text-volt-300"/> Your license key appears in your customer portal</li>
                        <li class="flex gap-2.5"><x-icon name="download" class="mt-0.5 text-volt-300"/> Download {{ setting('branding.product_name') }} and install it on your server</li>
                    </ul>
                </div>
            </aside>
        </form>
    </section>
</x-layouts.marketing>
