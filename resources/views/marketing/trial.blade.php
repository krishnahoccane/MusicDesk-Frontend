@php
    $user = auth()->user();
    $product = setting('branding.product_name', 'LabelDesk');
    $countries = ['US' => 'United States', 'IN' => 'India', 'GB' => 'United Kingdom', 'DE' => 'Germany', 'FR' => 'France', 'IT' => 'Italy', 'ES' => 'Spain', 'NL' => 'Netherlands', 'IE' => 'Ireland', 'CA' => 'Canada', 'AU' => 'Australia', 'NZ' => 'New Zealand', 'SG' => 'Singapore', 'AE' => 'United Arab Emirates', 'JP' => 'Japan', 'BR' => 'Brazil', 'MX' => 'Mexico', 'ZA' => 'South Africa', 'NG' => 'Nigeria'];
    $country = old('country', $org?->country ?? (app()->bound('locale.country') ? app('locale.country') : null) ?? 'US');
@endphp
<x-layouts.marketing title="Free trial">
    <section class="mx-auto max-w-5xl px-4 pb-24 pt-16 sm:px-6">
        <div class="eyebrow">Free trial · no card needed</div>
        <h1 class="display mt-3 text-4xl sm:text-5xl">Try {{ $product }} {{ $plan->name }} free for {{ $plan->trial_days }} days</h1>
        <p class="mt-3 max-w-2xl text-ink-300">Install {{ $product }} on your own server with a trial key. Buy the plan any time to keep everything: the same key and domains carry over.</p>

        <div class="mt-10 grid gap-6 lg:grid-cols-[1fr_340px]">
            <form method="POST" action="{{ route('trial.store', $plan->code) }}" class="card card-pad space-y-5">
                @csrf
                @error('trial')<div class="rounded-xl border border-coral-500/30 bg-coral-500/10 p-3 text-sm text-coral-300">{{ $message }}</div>@enderror
                @guest<p class="text-sm text-ink-400">Already a customer? <a href="{{ route('login') }}" class="text-volt-300 hover:text-white">Log in</a> to start the trial on your account.</p>@endguest
                @auth<p class="text-sm text-ink-400">Starting the trial as {{ $user->name }} ({{ $user->email }})</p>@endauth
                <div class="grid gap-4 sm:grid-cols-2">
                    @guest
                        <div><label class="label" for="name">Your name</label><input id="name" name="name" value="{{ old('name') }}" class="input" autocomplete="name" required>@error('name')<p class="mt-1 text-xs text-coral-400">{{ $message }}</p>@enderror</div>
                        <div><label class="label" for="email">Work email</label><input id="email" type="email" name="email" value="{{ old('email') }}" class="input" autocomplete="email" required>@error('email')<p class="mt-1 text-xs text-coral-400">{{ $message }}</p>@enderror</div>
                        <div class="sm:col-span-2"><label class="label" for="password">Create a password</label><input id="password" type="password" name="password" class="input" autocomplete="new-password" minlength="10" required>@error('password')<p class="mt-1 text-xs text-coral-400">{{ $message }}</p>@enderror</div>
                    @endguest
                    <div><label class="label" for="company">Organisation</label><input id="company" name="company" value="{{ old('company', $org?->name) }}" class="input" autocomplete="organization" required>@error('company')<p class="mt-1 text-xs text-coral-400">{{ $message }}</p>@enderror</div>
                    <div><label class="label" for="country">Country</label>
                        <select id="country" name="country" class="input">@foreach ($countries as $cc => $cn)<option value="{{ $cc }}" @selected($country === $cc)>{{ $cn }}</option>@endforeach</select></div>
                </div>
                <label class="flex items-start gap-3 text-sm text-ink-400">
                    <input type="checkbox" name="terms" value="1" class="mt-0.5 size-4 accent-volt-500" @checked(old('terms')) required>
                    <span>I agree to the <a href="{{ route('legal', 'terms') }}" target="_blank" class="text-ink-200 underline decoration-white/20 underline-offset-2">Terms</a> and <a href="{{ route('legal', 'license-agreement') }}" target="_blank" class="text-ink-200 underline decoration-white/20 underline-offset-2">License Agreement</a>.</span>
                </label>
                @error('terms')<p class="text-xs text-coral-400">{{ $message }}</p>@enderror
                <button class="btn btn-primary btn-lg w-full"><x-icon name="key" class="size-4"/> Start my {{ $plan->trial_days }}-day free trial</button>
            </form>

            <aside class="card card-pad h-fit space-y-4 text-sm">
                <div class="font-semibold text-white">What the trial includes</div>
                <ul class="space-y-2 text-ink-300">
                    <li class="flex gap-2"><x-icon name="check" class="mt-0.5 size-4 shrink-0 text-mint-400"/> {{ $plan->trial_days }} days of {{ $product }} {{ $plan->name }}</li>
                    <li class="flex gap-2"><x-icon name="check" class="mt-0.5 size-4 shrink-0 text-mint-400"/> {{ $limits['domains'] }} production {{ Str::plural('domain', $limits['domains']) }}, staging domains free</li>
                    @foreach (\App\Models\Plan::TRIAL_LIMITS as $k => $label)
                        @if ($limits[$k] !== null)<li class="flex gap-2"><x-icon name="alert" class="mt-0.5 size-4 shrink-0 text-amber-glow"/> Up to {{ number_format($limits[$k]) }} {{ strtolower($label) }}</li>@endif
                    @endforeach
                    <li class="flex gap-2"><x-icon name="{{ $limits['addons'] ? 'check' : 'alert' }}" class="mt-0.5 size-4 shrink-0 {{ $limits['addons'] ? 'text-mint-400' : 'text-amber-glow' }}"/> {{ $limits['addons'] ? 'The plan’s add-ons included' : 'Add-ons not included' }}</li>
                    @if ($limits['note'])<li class="flex gap-2"><x-icon name="alert" class="mt-0.5 size-4 shrink-0 text-amber-glow"/> {{ $limits['note'] }}</li>@endif
                </ul>
                <p class="border-t hairline pt-4 text-xs text-ink-500">When the trial ends, {{ $product }} turns read-only for {{ \App\Models\License::READ_ONLY_DAYS }} days so you can buy without losing anything, then the trial is suspended. One free trial per account.</p>
                <a href="{{ route('checkout', $plan->code) }}" class="btn btn-outline w-full">Or buy {{ $plan->name }} now</a>
            </aside>
        </div>
    </section>
</x-layouts.marketing>
