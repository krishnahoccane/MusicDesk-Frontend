@php $icons = ['razorpay' => 'zap', 'stripe' => 'card', 'paypal' => 'wallet', 'bank_transfer' => 'receipt']; @endphp
<x-layouts.app area="admin" title="Payment gateways" subtitle="Turn gateways on, add API keys and choose which currencies each one accepts.">
    <x-slot:eyebrow>Admin · Payments</x-slot:eyebrow>

    <div class="mb-4 rounded-2xl border hairline bg-white/[0.02] p-4 text-sm text-ink-300">
        Secrets are stored encrypted and never shown again; leave a secret blank to keep the saved one. At checkout, customers only see gateways that are enabled, fully configured and support their currency.
        More gateways (Cashfree, PhonePe, Paystack, Mollie…) can be added as drivers. See <span class="font-mono">app/Payments/PaymentGateway.php</span>.
    </div>

    <div class="grid gap-4 xl:grid-cols-2">
        @foreach ($gateways as $code => ['model' => $model, 'driver' => $driver])
            @php $configured = $driver->isConfigured(); $supported = $driver->supportedCurrencies(); @endphp
            <form method="POST" action="{{ route('admin.gateways.update', $code) }}" class="card" x-data="{ enabled: {{ $model->enabled ? 'true' : 'false' }}, mode: @js($model->mode ?? 'test') }">
                @csrf
                <div class="flex items-center gap-4 border-b hairline p-5 sm:p-6">
                    <span class="grid size-11 place-items-center rounded-xl bg-white/[0.04] text-volt-300 ring-1 ring-white/[0.06]"><x-icon :name="$icons[$code] ?? 'card'" class="size-5"/></span>
                    <div class="flex-1">
                        <div class="flex items-center gap-2"><span class="font-semibold text-white">{{ $driver->name() }}</span>
                            @if ($model->enabled && $configured)<span class="badge badge-mint badge-dot">Live at checkout</span>
                            @elseif ($model->enabled)<span class="badge badge-amber">Missing keys</span>
                            @else<span class="badge badge-ink">Off</span>@endif
                        </div>
                        <div class="text-xs text-ink-500">{{ $supported ? 'Supports '.implode(', ', array_slice($supported, 0, 8)).(count($supported) > 8 ? '…' : '') : 'Supports all currencies' }}</div>
                    </div>
                    <input type="hidden" name="enabled" :value="enabled ? 1 : 0">
                    <button type="button" class="switch" :aria-checked="enabled.toString()" @click="enabled = !enabled" role="switch" aria-label="Enable {{ $driver->name() }}"><span></span></button>
                </div>
                <div class="space-y-4 p-5 sm:p-6">
                    @if ($code !== 'bank_transfer')
                        <div class="flex gap-2">
                            @foreach (['test' => 'Test mode', 'live' => 'Live mode'] as $m => $ml)
                                <label class="flex-1 cursor-pointer rounded-xl border px-3 py-2 text-center text-sm transition" :class="mode === '{{ $m }}' ? '{{ $m === 'live' ? 'border-mint-500/50 bg-mint-500/10 text-white' : 'border-amber-glow/50 bg-amber-glow/10 text-white' }}' : 'border-white/[0.08] text-ink-400'">
                                    <input type="radio" name="mode" value="{{ $m }}" x-model="mode" class="sr-only">{{ $ml }}
                                </label>
                            @endforeach
                        </div>
                    @else
                        <input type="hidden" name="mode" value="live">
                    @endif

                    @foreach ($driver->credentialFields() as $key => $field)
                        @php $has = filled($model->credential($key)); $secret = $field['secret'] ?? false; @endphp
                        <div>
                            <label class="label" for="{{ $code }}_{{ $key }}">{{ $field['label'] }} @if (! ($field['required'] ?? true))<span class="text-ink-500">(optional)</span>@endif</label>
                            @if ($key === 'instructions')
                                <textarea id="{{ $code }}_{{ $key }}" name="credentials[{{ $key }}]" rows="2" class="input text-sm">{{ $model->credential($key) }}</textarea>
                            @else
                                <input id="{{ $code }}_{{ $key }}" name="credentials[{{ $key }}]" type="{{ $secret ? 'password' : 'text' }}" autocomplete="off"
                                       value="{{ $secret ? '' : $model->credential($key) }}" placeholder="{{ $secret && $has ? '•••••••• saved; leave blank to keep' : '' }}" class="input font-mono text-sm">
                            @endif
                            @isset($field['help'])<p class="mt-1 text-xs text-ink-500">{{ $field['help'] }}</p>@endisset
                        </div>
                    @endforeach

                    <div>
                        <div class="label">Accept for currencies <span class="text-ink-500">(none ticked = all it supports)</span></div>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach ($currencies as $cur)
                                @php $possible = ! $supported || in_array($cur, $supported, true); @endphp
                                <label class="cursor-pointer {{ $possible ? '' : 'pointer-events-none opacity-30' }}">
                                    <input type="checkbox" name="currencies[]" value="{{ $cur }}" class="peer sr-only" @checked(in_array($cur, $model->currencies ?? [], true)) @disabled(! $possible)>
                                    <span class="block rounded-lg border border-white/[0.08] px-2 py-1 font-mono text-[11px] text-ink-400 transition peer-checked:border-volt-500/60 peer-checked:bg-volt-500/10 peer-checked:text-white">{{ $cur }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    @if ($code !== 'bank_transfer')
                        <div class="rounded-xl border hairline bg-ink-950/60 p-3" x-data="{ url: @js(route('webhooks.gateway', $code)) }">
                            <div class="flex items-center justify-between"><span class="text-xs text-ink-500">Webhook URL (paste in the {{ $driver->name() }} dashboard)</span><button type="button" class="text-xs text-volt-300" @click="$copy(url)">Copy</button></div>
                            <code class="mt-1 block truncate font-mono text-[12px] text-ink-200" x-text="url"></code>
                        </div>
                    @endif
                </div>
                <div class="flex justify-end border-t hairline px-5 py-4 sm:px-6"><button class="btn btn-primary btn-sm">Save {{ $driver->name() }}</button></div>
            </form>
        @endforeach
    </div>
</x-layouts.app>
