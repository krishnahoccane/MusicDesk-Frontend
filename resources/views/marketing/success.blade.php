@php
    use App\Support\Demo;
    $paid = $order->isPaid();
    $awaiting = $order->status === 'awaiting_payment';
    $failed = in_array($order->status, ['failed', 'cancelled', 'pending'], true);
    $key = $order->license_key;
@endphp
<x-layouts.marketing title="Order {{ $order->number }}">
    <section class="mx-auto max-w-2xl px-4 pt-16 text-center sm:px-6" x-data="{ show: false }">
        <div class="relative mx-auto grid size-20 place-items-center">
            @if ($paid)<span class="absolute inset-0 animate-ping rounded-full bg-mint-500/20 [animation-iteration-count:2]"></span>@endif
            <span class="relative grid size-20 place-items-center rounded-full {{ $paid ? 'bg-gradient-to-br from-mint-400 to-sky-glow text-ink-950' : ($awaiting ? 'bg-amber-glow/20 text-amber-glow' : 'bg-coral-500/15 text-coral-400') }}">
                <x-icon :name="$paid ? 'check' : ($awaiting ? 'receipt' : 'alert')" class="size-9" stroke="2.5"/>
            </span>
        </div>

        @if ($paid)
            <h1 class="display mt-8 text-4xl sm:text-5xl">You’re licensed. <span class="serif-accent text-gradient">Time to build your brand.</span></h1>
            <p class="mx-auto mt-4 max-w-md text-ink-300">Payment received. Invoice {{ $order->invoice?->number }} has been issued to {{ $order->organisation->name }}.</p>
        @elseif ($awaiting)
            <h1 class="display mt-8 text-4xl sm:text-5xl">Order placed. <span class="serif-accent text-gradient">Awaiting payment.</span></h1>
            <p class="mx-auto mt-4 max-w-md text-ink-300">Pay invoice {{ $order->invoice?->number }} by bank transfer within {{ setting('invoice.due_days') }} days. Your license works in the meantime.</p>
        @else
            <h1 class="display mt-8 text-4xl sm:text-5xl">Payment not completed</h1>
            <p class="mx-auto mt-4 max-w-md text-ink-300">{{ $errors->first('payment') ?: 'Nothing was charged. You can try again below.' }}</p>
        @endif

        @if (($paid || $awaiting) && $key)
            <div class="glow-border relative mt-10 overflow-hidden rounded-3xl border border-transparent bg-ink-900/80 p-6 text-left">
                <div class="flex items-center justify-between">
                    <div class="eyebrow">License key · {{ $order->meta['license_number'] ?? '' }}</div>
                    <span class="badge badge-dot {{ $paid ? 'badge-mint' : 'badge-amber' }}">{{ $paid ? 'Active' : 'Grace period' }}</span>
                </div>
                <div class="mt-3 flex items-center gap-2">
                    <code class="min-w-0 flex-1 truncate rounded-xl border hairline bg-ink-950 px-4 py-3.5 font-mono text-sm tracking-[0.14em] text-white sm:text-lg"
                          x-text="show ? @js($key) : @js(substr($key, 0, 5).'••••-••••-••••-'.substr($key, -4))"></code>
                    <button @click="show = !show" class="btn btn-outline !px-3 !py-3.5" aria-label="Show key"><x-icon name="eye" class="size-4"/></button>
                    <button @click="$copy(@js($key))" class="btn btn-outline !px-3 !py-3.5" aria-label="Copy key"><x-icon name="copy" class="size-4"/></button>
                </div>
                <div class="mt-5 grid grid-cols-3 gap-3 text-sm">
                    <div><div class="text-ink-500">License</div><div class="text-white">{{ $order->plan?->name }} · {{ ucfirst($order->billing_type) }}</div></div>
                    <div><div class="text-ink-500">Domains</div><div class="text-white">{{ ($order->plan?->domains ?? 1) + $order->extra_domains }}</div></div>
                    <div><div class="text-ink-500">Paid</div><div class="text-white">{{ fmt_money($order->total, $order->currency) }}</div></div>
                </div>
            </div>
        @elseif (($paid || $awaiting) && $order->billing_license_id)
            {{-- Live billing never sends the key with the order: reveal it on request (billing audits each reveal). --}}
            <div class="glow-border relative mt-10 overflow-hidden rounded-3xl border border-transparent bg-ink-900/80 p-6 text-left"
                 x-data="{ key: null, async reveal() { this.key = (await portalApi(@js(route('dashboard.licenses.reveal', $order->billing_license_id)))).key } }">
                <div class="flex items-center justify-between">
                    <div class="eyebrow">License key · {{ $order->meta['license_number'] ?? '' }}</div>
                    <span class="badge badge-dot {{ $paid ? 'badge-mint' : 'badge-amber' }}">{{ $paid ? 'Active' : 'Grace period' }}</span>
                </div>
                <div class="mt-3 flex items-center gap-2">
                    <code class="min-w-0 flex-1 truncate rounded-xl border hairline bg-ink-950 px-4 py-3.5 font-mono text-sm tracking-[0.14em] text-white sm:text-lg" x-text="key ?? 'LDSK-••••-••••-••••-••••'"></code>
                    <button x-show="!key" @click="reveal()" class="btn btn-white !py-3.5">Reveal key</button>
                    <button x-show="key" x-cloak @click="$copy(key)" class="btn btn-outline !px-3 !py-3.5" aria-label="Copy key"><x-icon name="copy" class="size-4"/></button>
                </div>
                <p class="mt-3 text-xs text-ink-500">Your key is also in your customer portal under Licenses.</p>
            </div>
        @elseif ($paid || $awaiting)
            <p class="mt-8 rounded-2xl border border-amber-glow/25 bg-amber-glow/[0.06] p-4 text-sm text-amber-glow">Your license is being set up. It will appear in your customer portal within a few minutes.</p>
        @endif

        @if ($awaiting && $bank)
            <div class="mt-6 rounded-2xl border hairline p-5 text-left text-sm">
                <div class="font-medium text-white">Bank transfer details</div>
                <dl class="mt-3 grid grid-cols-[140px_1fr] gap-y-2">
                    <dt class="text-ink-500">Amount</dt><dd class="font-mono text-white">{{ fmt_money($order->total, $order->currency) }}</dd>
                    <dt class="text-ink-500">Reference</dt><dd class="font-mono text-white">{{ $order->invoice?->number ?? $order->number }}</dd>
                    @foreach (['account_name' => 'Account name', 'bank_name' => 'Bank', 'account_number' => 'Account / IBAN', 'routing' => 'SWIFT / IFSC'] as $k => $label)
                        @isset($bank[$k])<dt class="text-ink-500">{{ $label }}</dt><dd class="text-ink-100">{{ $bank[$k] }}</dd>@endisset
                    @endforeach
                </dl>
                @isset($bank['instructions'])<p class="mt-3 text-xs text-ink-400">{{ $bank['instructions'] }}</p>@endisset
            </div>
        @endif

        <div class="mt-6 flex flex-col gap-3 sm:flex-row">
            @if ($failed)
                <form method="POST" action="{{ route('checkout.pay', $order) }}" class="flex-1">@csrf<button class="btn btn-white btn-lg w-full">Try payment again</button></form>
                <a href="{{ route('checkout', $order->plan?->code ?? 'starter') }}" class="btn btn-outline btn-lg flex-1">Choose another method</a>
            @else
                @if ($order->invoice)
                    <a href="{{ route('dashboard.invoices.pdf', $order->invoice) }}" class="btn btn-outline btn-lg flex-1"><x-icon name="receipt"/> Invoice PDF</a>
                @endif
                <a href="{{ route('dashboard.downloads') }}" class="btn btn-white btn-lg flex-1"><x-icon name="download"/> Download {{ setting('branding.product_name') }} {{ Demo::LATEST_VERSION }}</a>
            @endif
        </div>
        <a href="{{ route('dashboard.licenses') }}" class="mt-6 inline-flex items-center gap-1.5 text-sm text-volt-300 hover:text-white">Go to your customer portal <x-icon name="arrow-right" class="size-3.5"/></a>
    </section>
</x-layouts.marketing>
