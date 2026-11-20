@php
    $cs = app(\App\Services\Currency\CurrencyService::class);
    $fmt = fn ($v) => $cs->format($v, $quote['currency'], true);
    $titles = [
        'renewal' => ['Renew your license', 'One more year of use, updates and support. The new year starts from your current renewal date, so you don’t lose any days by renewing early.'],
        'updates_renewal' => ['Renew updates & support', 'Your lifetime license keeps working either way. This adds 12 months of new versions and support, at 40% off the lifetime price.'],
        'extra_domains' => ['Add production domains', 'Each extra domain lets one more branded platform run on this license. Staging domains are always free.'],
        'change_plan' => ['Upgrade your plan', 'Your key, domains and dates stay the same. You only pay the difference.'],
        'convert_lifetime' => ['Switch to a lifetime license', 'Pay once and own it. We credit the unused part of your annual license. Includes 12 months of updates from today.'],
    ];
    [$title, $intro] = $titles[$kind];
    $icons = ['razorpay' => 'zap', 'stripe' => 'card', 'paypal' => 'wallet', 'bank_transfer' => 'receipt'];
@endphp
<x-layouts.app :title="$title" :subtitle="$intro">
    <x-slot:eyebrow><a href="{{ route('dashboard.licenses') }}" class="hover:text-white">Licenses</a> · {{ $license->number }}</x-slot:eyebrow>

    <form method="POST" action="{{ route('licenses.buy.store', [$license, $kind]) }}" class="grid gap-6 lg:grid-cols-[1fr_380px]"
          x-data="{
              quantity: {{ (int) $input['quantity'] }}, plan: @js($input['plan']?->code), gateway: @js(old('gateway', $gateways->first()?->code())), busy: false,
              q: { lines: {{ json_encode(array_map(fn ($l) => ['description' => $l['description'], 'quantity' => $l['quantity'], 'amount' => $fmt($l['amount'])], $quote['lines'])) }}, subtotal: @js($fmt($quote['subtotal'])), tax: @js($fmt($quote['tax'])), tax_rate: {{ round($quote['tax_rate'] * 100, 2) }}, total: @js($fmt($quote['total'])) },
              async requote() {
                  const r = await fetch(@js(route('licenses.buy.quote', [$license, $kind])), { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify({ quantity: this.quantity, plan: this.plan }) });
                  if (r.ok) this.q = await r.json();
              },
          }"
          x-init="$watch('quantity', () => requote()); $watch('plan', () => requote())" @submit="busy = true">
        @csrf
        <div class="space-y-4">
            {{-- Current license --}}
            <div class="card card-pad">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <div class="font-semibold text-white">{{ setting('branding.product_name') }} {{ $license->plan->name }} <span class="text-ink-500">· {{ ucfirst($license->billing_type) }}</span></div>
                        <div class="font-mono text-xs text-ink-500">{{ $license->number }} · {{ $license->maskedKey() }}</div>
                    </div>
                    <span class="badge {{ \App\Support\Demo::statusBadge($license->displayStatus()) }}">{{ ucfirst($license->displayStatus()) }}</span>
                </div>
                <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-3">
                    <div><dt class="text-xs text-ink-500">Production domains</dt><dd class="text-ink-100">{{ $license->domainsUsed() }} of {{ $license->domainLimit() }} in use</dd></div>
                    <div><dt class="text-xs text-ink-500">{{ $license->expires_at ? 'Renews' : 'Updates until' }}</dt><dd class="text-ink-100">{{ local_time($license->expires_at ?? $license->support_until)?->format('M j, Y') }}</dd></div>
                    <div><dt class="text-xs text-ink-500">Licensed to</dt><dd class="text-ink-100">{{ $license->organisation->name }}</dd></div>
                </dl>
            </div>

            {{-- Options --}}
            @if ($kind === 'extra_domains')
                <div class="card card-pad">
                    <div class="flex items-center justify-between gap-4">
                        <div><div class="font-medium text-white">How many extra domains?</div><div class="text-xs text-ink-500">{{ $license->billing_type === 'annual' ? 'Prorated to your renewal date, then renewed with the license.' : 'One-time, for the life of the license.' }}</div></div>
                        <div class="flex items-center gap-1">
                            <button type="button" @click="quantity = Math.max(1, quantity - 1)" class="grid size-9 place-items-center rounded-lg bg-white/[0.06] hover:bg-white/10" aria-label="Fewer">−</button>
                            <span class="w-10 text-center font-mono text-white" x-text="quantity"></span>
                            <button type="button" @click="quantity = Math.min(50, quantity + 1)" class="grid size-9 place-items-center rounded-lg bg-white/[0.06] hover:bg-white/10" aria-label="More">+</button>
                        </div>
                    </div>
                    <input type="hidden" name="quantity" :value="quantity">
                </div>
            @elseif ($kind === 'change_plan')
                <div class="card card-pad">
                    <div class="font-medium text-white">Upgrade to</div>
                    <div class="mt-3 grid gap-2 sm:grid-cols-{{ min(3, max(1, $upgrades->count())) }}">
                        @foreach ($upgrades as $p)
                            <label class="cursor-pointer rounded-xl border p-4 transition" :class="plan === '{{ $p->code }}' ? 'border-volt-500/60 bg-volt-500/10' : 'border-white/[0.08] hover:border-white/15'">
                                <input type="radio" name="plan" value="{{ $p->code }}" x-model="plan" class="sr-only">
                                <span class="block font-medium text-white">{{ $p->name }}</span>
                                <span class="text-xs text-ink-400">{{ $p->domains }} production domains · {{ money($license->billing_type === 'lifetime' ? ($p->price_lifetime ?? 0) : $p->price_annual)['display'] }}{{ $license->billing_type === 'lifetime' ? '' : ' / yr' }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Payment --}}
            <div class="card card-pad">
                <div class="font-medium text-white">Payment method</div>
                @if ($gateways->isEmpty())
                    <p class="mt-3 rounded-xl border border-amber-glow/25 bg-amber-glow/[0.06] p-4 text-sm text-amber-glow">No payment method is available for {{ $currency }}. Switch currency or contact us.</p>
                @else
                    <div class="mt-3 grid gap-2 sm:grid-cols-{{ min(4, $gateways->count()) }}">
                        @foreach ($gateways as $g)
                            <label class="flex cursor-pointer flex-col items-center gap-1.5 rounded-xl border px-3 py-3 text-sm transition" :class="gateway === '{{ $g->code() }}' ? 'border-volt-500/60 bg-volt-500/10 text-white' : 'border-white/[0.08] text-ink-300 hover:border-white/20'">
                                <input type="radio" name="gateway" value="{{ $g->code() }}" x-model="gateway" class="sr-only"><x-icon :name="$icons[$g->code()] ?? 'card'" class="size-5"/>{{ $g->name() }}
                            </label>
                        @endforeach
                    </div>
                    <p class="mt-3 text-xs text-ink-500" x-show="gateway === 'bank_transfer'" x-cloak>We email an invoice with our bank details. The change is applied when the transfer arrives.</p>
                @endif
            </div>

            <label class="flex items-start gap-3 text-sm text-ink-400">
                <input type="checkbox" name="terms" value="1" class="mt-0.5 size-4 accent-volt-500" required>
                <span>I agree to the <a href="{{ route('legal', 'terms') }}" target="_blank" class="text-ink-200 underline decoration-white/20">Terms</a> and the <a href="{{ route('legal', 'license-agreement') }}" target="_blank" class="text-ink-200 underline decoration-white/20">License Agreement</a>.</span>
            </label>
        </div>

        {{-- Summary --}}
        <aside class="lg:sticky lg:top-24 lg:self-start">
            <div class="glow-border relative overflow-hidden rounded-3xl border border-transparent bg-ink-900/80 p-6">
                <div class="flex items-center justify-between"><span class="eyebrow">Order summary</span><x-currency-switcher/></div>
                <dl class="mt-5 space-y-3 text-sm">
                    <template x-for="l in q.lines" :key="l.description">
                        <div class="flex justify-between gap-4"><dt class="text-ink-300"><span x-text="l.description"></span><span x-show="l.quantity > 1" class="text-ink-500" x-text="' × ' + l.quantity"></span></dt><dd class="shrink-0 font-mono text-ink-100" x-text="l.amount"></dd></div>
                    </template>
                </dl>
                <dl class="mt-4 space-y-2 border-t hairline pt-4 text-sm">
                    <div class="flex justify-between"><dt class="text-ink-400">Subtotal</dt><dd class="font-mono text-ink-100" x-text="q.subtotal"></dd></div>
                    <div class="flex justify-between"><dt class="text-ink-400">Tax <span x-show="q.tax_rate > 0" x-text="'(' + q.tax_rate + '%)'"></span></dt><dd class="font-mono text-ink-100" x-text="q.tax"></dd></div>
                </dl>
                <div class="mt-4 flex items-end justify-between border-t hairline pt-4">
                    <span class="text-sm text-ink-300">Due today</span>
                    <span class="text-3xl font-semibold tracking-tight text-white" x-text="q.total"></span>
                </div>
                <button class="btn btn-primary btn-lg mt-5 w-full" :disabled="busy" @disabled($gateways->isEmpty())>
                    <x-icon name="lock" class="size-4"/> <span x-text="busy ? 'Processing…' : (gateway === 'bank_transfer' ? 'Place order' : 'Pay ' + q.total)"></span>
                </button>
                <p class="mt-3 text-center text-xs text-ink-500">You’ll get an invoice by email. 30-day money-back guarantee.</p>
            </div>
        </aside>
    </form>
</x-layouts.app>
