<x-layouts.auth title="Create account">
    <h1 class="display text-3xl">Create your organisation</h1>
    <p class="mt-2 text-sm text-ink-400">Your organisation is where licenses, invoices and your team live.</p>

    <form method="POST" action="{{ route('register.attempt') }}" class="mt-8 space-y-4" x-data="{ pw: '' }">
        @csrf
        <div class="grid grid-cols-2 gap-3">
            <div><label class="label" for="name">Your name</label><input id="name" name="name" value="{{ old('name') }}" class="input" autocomplete="name" required></div>
            <div><label class="label" for="company">Organisation</label><input id="company" name="company" value="{{ old('company') }}" class="input" autocomplete="organization" required></div>
        </div>
        @error('name')<p class="text-xs text-coral-400">{{ $message }}</p>@enderror
        <div><label class="label" for="email">Work email</label><input id="email" type="email" name="email" value="{{ old('email') }}" class="input" placeholder="you@yourcompany.com" autocomplete="email" required>
            @error('email')<p class="mt-1 text-xs text-coral-400">{{ $message }}</p>@enderror</div>
        @php $countries = ['US' => 'United States', 'IN' => 'India', 'GB' => 'United Kingdom', 'DE' => 'Germany', 'FR' => 'France', 'IT' => 'Italy', 'ES' => 'Spain', 'NL' => 'Netherlands', 'IE' => 'Ireland', 'CA' => 'Canada', 'AU' => 'Australia', 'NZ' => 'New Zealand', 'SG' => 'Singapore', 'AE' => 'United Arab Emirates', 'JP' => 'Japan', 'BR' => 'Brazil', 'MX' => 'Mexico', 'ZA' => 'South Africa', 'NG' => 'Nigeria'];
            $detected = old('country', app()->bound('locale.country') ? app('locale.country') : null); @endphp
        <div><label class="label" for="country">Country</label>
            <select id="country" name="country" class="input" required>
                <option value="" disabled @selected(! isset($countries[$detected]))>Select your country</option>
                @foreach ($countries as $cc => $cn)<option value="{{ $cc }}" @selected($detected === $cc)>{{ $cn }}</option>@endforeach
            </select>
            @error('country')<p class="mt-1 text-xs text-coral-400">{{ $message }}</p>@enderror</div>
        <div><label class="label" for="intent">What are you building?</label>
            <select id="intent" name="intent" class="input"><option>A platform for my own label</option><option>A distribution service for other labels & artists</option><option>Platforms for my clients (agency / reseller)</option><option>Just evaluating</option></select></div>
        <div>
            <label class="label" for="password">Password</label>
            <input id="password" type="password" name="password" x-model="pw" class="input" placeholder="At least 10 characters" autocomplete="new-password" minlength="10" required>
            <div class="mt-2 flex gap-1">
                <template x-for="i in 4"><span class="h-1 flex-1 rounded-full transition" :class="pw.length >= i * 3 ? (pw.length >= 12 ? 'bg-mint-400' : 'bg-amber-glow') : 'bg-white/10'"></span></template>
            </div>
            @error('password')<p class="mt-1 text-xs text-coral-400">{{ $message }}</p>@enderror
        </div>
        <button class="btn btn-primary w-full !py-3">Create organisation</button>
        <p class="text-center text-xs text-ink-500">By continuing you agree to our <a href="{{ route('legal', 'terms') }}" class="underline">Terms</a> and <a href="{{ route('legal', 'privacy') }}" class="underline">Privacy Policy</a>.</p>
    </form>
    <p class="mt-6 text-center text-sm text-ink-400">Already a customer? <a href="{{ route('login') }}" class="text-white hover:text-volt-300">Log in</a></p>
</x-layouts.auth>
