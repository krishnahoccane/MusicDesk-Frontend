@php $icons = ['razorpay' => 'zap', 'stripe' => 'card', 'paypal' => 'wallet', 'bank_transfer' => 'receipt']; @endphp
<x-layouts.app area="admin" title="Payment gateways" subtitle="Switch gateways on or off. Click a gateway to enter its API keys and choose the currencies it accepts.">
    <x-slot:eyebrow>Admin · Payments</x-slot:eyebrow>

    <div class="card divide-y divide-white/[0.06] overflow-hidden">
        @foreach ($gateways as $code => ['model' => $model, 'driver' => $driver])
            @php
                $configured = $driver->isConfigured();
                $supported = $driver->supportedCurrencies();
                $accepts = $model->currencies ?: null;
            @endphp
            <div x-data="{ open: @js($errors->any() && old('gateway') === $code), enabled: @js((bool) $model->enabled), mode: @js($model->mode ?? 'test'), configured: @js($configured), busy: false,
                    async toggle() {
                        if (this.busy) return; this.busy = true;
                        try { const r = await portalApi(@js(route('admin.gateways.toggle', $code)), 'POST', { enabled: !this.enabled }); this.enabled = r.enabled; toast(r.message) } catch (e) {} finally { this.busy = false }
                    } }">
                {{-- One line per gateway: click to expand, toggle to switch on/off --}}
                <div class="flex cursor-pointer items-center gap-4 px-5 py-4 transition hover:bg-white/[0.02]" @click="open = !open" role="button" :aria-expanded="open" tabindex="0" @keydown.enter.prevent="open = !open">
                    <span class="grid size-9 shrink-0 place-items-center rounded-xl bg-white/[0.04] text-volt-300 ring-1 ring-white/[0.06]"><x-icon :name="$icons[$code] ?? 'card'" class="size-4"/></span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium text-white">{{ $driver->name() }}</span>
                            <span x-show="enabled && configured" class="badge badge-mint badge-dot">Live at checkout</span>
                            <span x-show="enabled && ! configured" x-cloak class="badge badge-amber">Missing keys</span>
                            <span x-show="! enabled" x-cloak class="badge badge-ink">Off</span>
                            @if ($code !== 'bank_transfer')<span class="badge {{ ($model->mode ?? 'test') === 'live' ? 'badge-volt' : 'badge-amber' }}">{{ ($model->mode ?? 'test') === 'live' ? 'Live mode' : 'Test mode' }}</span>@endif
                        </div>
                        <div class="mt-0.5 truncate text-xs text-ink-500">
                            {{ $accepts ? 'Accepts '.implode(', ', $accepts) : ($supported ? 'Accepts '.implode(', ', array_slice($supported, 0, 6)).(count($supported) > 6 ? '…' : '') : 'Accepts all currencies') }}
                            · {{ $configured ? 'Keys saved' : 'No keys yet' }}
                        </div>
                    </div>
                    <button type="button" class="switch" :aria-checked="enabled.toString()" @click.stop="toggle()" :disabled="busy" role="switch" aria-label="Turn {{ $driver->name() }} on or off"><span></span></button>
                    <x-icon name="chevron-down" class="size-4 shrink-0 text-ink-500 transition" ::class="open && 'rotate-180'"/>
                </div>

                {{-- Credentials, mode and currencies --}}
                <form x-show="open" x-collapse x-cloak method="POST" action="{{ route('admin.gateways.update', $code) }}" class="border-t hairline bg-ink-950/40">
                    @csrf
                    <input type="hidden" name="gateway" value="{{ $code }}">
                    <input type="hidden" name="enabled" :value="enabled ? 1 : 0">
                    <div class="grid gap-4 p-5 sm:grid-cols-2 sm:px-6">
                        @if ($code !== 'bank_transfer')
                            <div class="flex gap-2 sm:col-span-2">
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
                            <div class="{{ $key === 'instructions' ? 'sm:col-span-2' : '' }}">
                                <label class="label" for="{{ $code }}_{{ $key }}">{{ $field['label'] }} @if (! ($field['required'] ?? true))<span class="text-ink-500">(optional)</span>@endif</label>
                                @if ($key === 'instructions')
                                    <textarea id="{{ $code }}_{{ $key }}" name="credentials[{{ $key }}]" rows="3" class="input text-sm">{{ $model->credential($key) }}</textarea>
                                @else
                                    <input id="{{ $code }}_{{ $key }}" name="credentials[{{ $key }}]" type="{{ $secret ? 'password' : 'text' }}" autocomplete="off"
                                           value="{{ $secret ? '' : $model->credential($key) }}" placeholder="{{ $secret && $has ? '•••••••• saved; leave blank to keep' : '' }}" class="input font-mono text-sm">
                                @endif
                                @isset($field['help'])<p class="mt-1 text-xs text-ink-500">{{ $field['help'] }}</p>@endisset
                            </div>
                        @endforeach

                        <div class="sm:col-span-2">
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
                            <div class="rounded-xl border hairline bg-ink-950/60 p-3 sm:col-span-2" x-data="{ url: @js(route('webhooks.gateway', $code)) }">
                                <div class="flex items-center justify-between"><span class="text-xs text-ink-500">Webhook URL (paste in the {{ $driver->name() }} dashboard)</span><button type="button" class="text-xs text-volt-300" @click="$copy(url)">Copy</button></div>
                                <code class="mt-1 block truncate font-mono text-[12px] text-ink-200" x-text="url"></code>
                            </div>
                        @endif
                    </div>
                    <div class="flex items-center justify-between gap-3 border-t hairline px-5 py-3 sm:px-6">
                        <p class="text-[11px] text-ink-500">Secrets are stored encrypted and never shown again; leave a secret blank to keep the saved one.</p>
                        <button class="btn btn-primary btn-sm shrink-0">Save {{ $driver->name() }}</button>
                    </div>
                </form>
            </div>
        @endforeach
    </div>

    <p class="mt-4 text-xs text-ink-500">At checkout, customers only see gateways that are on, have their keys and accept the customer’s currency. More gateways (Cashfree, PhonePe, Paystack, Mollie…) can be added as drivers: see <span class="font-mono">app/Payments/PaymentGateway.php</span>.</p>
</x-layouts.app>
