@php
    $paid = $order->isPaid();
    $awaiting = $order->status === 'awaiting_payment';
    $failed = in_array($order->status, ['failed', 'cancelled', 'pending'], true);
    $license = $order->license;
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
            <h1 class="display mt-8 text-4xl sm:text-5xl">@if ($order->isNewLicense())You’re licensed. <span class="serif-accent text-gradient">Time to build your brand.</span>@else Done. <span class="serif-accent text-gradient">{{ $order->kindLabel() }} complete.</span>@endif</h1>
            <p class="mx-auto mt-4 max-w-md text-ink-300">Payment received. Invoice {{ $order->invoice?->number }} has been issued to {{ $order->organisation->name }}.</p>
        @elseif ($awaiting)
            <h1 class="display mt-8 text-4xl sm:text-5xl">Order placed. <span class="serif-accent text-gradient">Awaiting payment.</span></h1>
            <p class="mx-auto mt-4 max-w-md text-ink-300">Pay invoice {{ $order->invoice?->number }} by bank transfer within {{ setting('invoice.due_days') }} days. Your license works in the meantime.</p>
        @else
            <h1 class="display mt-8 text-4xl sm:text-5xl">Payment not completed</h1>
            <p class="mx-auto mt-4 max-w-md text-ink-300">{{ $errors->first('payment') ?: 'Nothing was charged. You can try again below.' }}</p>
        @endif

        @if (($paid || $awaiting) && $license && $order->isNewLicense())
            {{-- The key is revealed on request; each reveal is recorded in the license history. --}}
            <div class="glow-border relative mt-10 overflow-hidden rounded-3xl border border-transparent bg-ink-900/80 p-6 text-left"
                 x-data="{ key: null, async reveal() { this.key = (await portalApi(@js(route('dashboard.licenses.reveal', $license)))).key } }">
                <div class="flex items-center justify-between">
                    <div class="eyebrow">License key · {{ $license->number }}</div>
                    <span class="badge badge-dot {{ $paid ? 'badge-mint' : 'badge-amber' }}">{{ $paid ? 'Active' : 'Grace period until '.local_time($license->pending_until)?->format('M j') }}</span>
                </div>
                <div class="mt-3 flex items-center gap-2">
                    <code class="min-w-0 flex-1 truncate rounded-xl border hairline bg-ink-950 px-4 py-3.5 font-mono text-sm tracking-[0.14em] text-white sm:text-lg" x-text="key ?? @js($license->maskedKey())"></code>
                    <button x-show="!key" @click="reveal()" class="btn btn-white !py-3.5">Reveal key</button>
                    <button x-show="key" x-cloak @click="$copy(key)" class="btn btn-outline !px-3 !py-3.5" aria-label="Copy key"><x-icon name="copy" class="size-4"/></button>
                </div>
                <div class="mt-5 grid grid-cols-3 gap-3 text-sm">
                    <div><div class="text-ink-500">License</div><div class="text-white">{{ $license->plan?->name }} · {{ ucfirst($license->billing_type) }}</div></div>
                    <div><div class="text-ink-500">Domains</div><div class="text-white">{{ $license->domainLimit() }}</div></div>
                    <div><div class="text-ink-500">Paid</div><div class="text-white">{{ fmt_money($order->total, $order->currency) }}</div></div>
                </div>
                <p class="mt-4 text-xs text-ink-500">Your key is also in your customer portal under Licenses.</p>
            </div>
        @elseif ($paid && $license)
            <div class="mt-10 rounded-3xl border hairline bg-ink-900/80 p-6 text-left text-sm">
                <div class="font-medium text-white">{{ $order->kindLabel() }} applied to {{ $license->number }}</div>
                <dl class="mt-3 grid grid-cols-[160px_1fr] gap-y-2">
                    <dt class="text-ink-500">Plan</dt><dd class="text-ink-100">{{ $license->plan?->name }} · {{ ucfirst($license->billing_type) }}</dd>
                    <dt class="text-ink-500">Production domains</dt><dd class="text-ink-100">{{ $license->domainLimit() }}</dd>
                    @if ($license->expires_at)<dt class="text-ink-500">Renews</dt><dd class="text-ink-100">{{ local_time($license->expires_at)->format('M j, Y') }}</dd>@endif
                    <dt class="text-ink-500">Updates & support until</dt><dd class="text-ink-100">{{ local_time($license->support_until)?->format('M j, Y') }}</dd>
                </dl>
            </div>
        @elseif ($awaiting && ! $order->isNewLicense())
            <p class="mt-8 rounded-2xl border border-amber-glow/25 bg-amber-glow/[0.06] p-4 text-sm text-amber-glow">The {{ strtolower($order->kindLabel()) }} is applied to your license as soon as the transfer arrives.</p>
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
                <a href="{{ $order->isNewLicense() || ! $license ? route('checkout', $order->plan?->code ?? 'starter') : route('licenses.buy', [$license, $order->kind]) }}" class="btn btn-outline btn-lg flex-1">Choose another method</a>
            @else
                @if ($order->invoice)
                    <a href="{{ route('dashboard.invoices.pdf', $order->invoice) }}" class="btn btn-outline btn-lg flex-1"><x-icon name="receipt"/> Invoice PDF</a>
                @endif
                <a href="{{ route('dashboard.downloads') }}" class="btn btn-white btn-lg flex-1"><x-icon name="download"/> Download {{ setting('branding.product_name') }} {{ \App\Models\Release::latestVersion() }}</a>
            @endif
        </div>
        <a href="{{ route('dashboard.licenses') }}" class="mt-6 inline-flex items-center gap-1.5 text-sm text-volt-300 hover:text-white">Go to your customer portal <x-icon name="arrow-right" class="size-3.5"/></a>
    </section>
</x-layouts.marketing>
