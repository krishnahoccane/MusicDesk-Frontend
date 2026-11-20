{{-- Opens a JS checkout widget (Razorpay). On success it posts the signed result to checkout.verify. --}}
<x-layouts.marketing title="Complete payment">
    <section class="mx-auto max-w-lg px-4 pt-20 text-center sm:px-6" x-data="{ open() { window.__pay() } }" x-init="setTimeout(() => open(), 300)">
        <span class="mx-auto grid size-16 place-items-center rounded-2xl bg-volt-500/15 text-volt-300"><x-icon name="lock" class="size-7"/></span>
        <h1 class="display mt-6 text-3xl">Complete your payment</h1>
        <p class="mt-3 text-ink-400">Order <span class="font-mono text-ink-200">{{ $order->number }}</span> · {{ fmt_money($order->total, $order->currency) }}</p>
        <p class="mt-1 text-sm text-ink-500">A secure {{ $gateway->name() }} window should open. If it doesn’t, use the button below.</p>
        <div class="mt-8 flex flex-col gap-3 sm:flex-row sm:justify-center">
            <button class="btn btn-primary btn-lg" @click="open()">Pay {{ fmt_money($order->total, $order->currency) }}</button>
            <a href="{{ $payload['cancel'] }}" class="btn btn-outline btn-lg">Cancel</a>
        </div>

        <form id="verify" method="POST" action="{{ $payload['callback'] }}" class="hidden">
            @csrf
            <input type="hidden" name="razorpay_order_id">
            <input type="hidden" name="razorpay_payment_id">
            <input type="hidden" name="razorpay_signature">
        </form>
    </section>

    @if ($gateway->code() === 'razorpay')
        <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
        <script>
            window.__pay = function () {
                const p = @json(\Illuminate\Support\Arr::except($payload, ['callback', 'cancel']));
                const rzp = new Razorpay({
                    ...p,
                    handler: function (res) {
                        const f = document.getElementById('verify');
                        f.razorpay_order_id.value = res.razorpay_order_id;
                        f.razorpay_payment_id.value = res.razorpay_payment_id;
                        f.razorpay_signature.value = res.razorpay_signature;
                        f.submit();
                    },
                    modal: { ondismiss: function () { window.toast && toast('Payment window closed. You can try again.') } },
                });
                rzp.on('payment.failed', function (r) { window.toast && toast(r.error?.description || 'Payment failed', 'danger') });
                rzp.open();
            };
        </script>
    @endif
</x-layouts.marketing>
