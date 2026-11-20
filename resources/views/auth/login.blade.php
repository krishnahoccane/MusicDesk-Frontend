<x-layouts.auth title="Sign in">
    @if (request()->boolean('installed'))
        <div class="mb-6 rounded-xl border border-mint-500/25 bg-mint-500/[0.07] p-4 text-sm text-mint-400" role="status">
            <div class="font-medium">Installation complete.</div>
            <div class="mt-1 text-ink-300">Sign in with your super admin account to open the admin dashboard. Next: add your payment gateway keys and set up the daily scheduler (<span class="font-mono">php artisan schedule:run</span> every minute).</div>
            @unless (is_link(public_path('storage')) || is_dir(public_path('storage')))
                <div class="mt-1 text-amber-glow">Uploaded logos need a storage link: run <span class="font-mono">php artisan storage:link</span>.</div>
            @endunless
        </div>
    @endif
    <h1 class="display text-3xl">Customer portal</h1>
    <p class="mt-2 text-sm text-ink-400">Log in to manage your licenses, downloads and invoices.</p>

    <div class="mt-8 grid grid-cols-2 gap-2">
        <button class="btn btn-outline"><svg class="size-4" viewBox="0 0 24 24"><path fill="#fff" d="M21.8 12.2c0-.7-.1-1.4-.2-2H12v3.8h5.5a4.7 4.7 0 0 1-2 3.1v2.5h3.3c1.9-1.8 3-4.4 3-7.4z"/><path fill="#a9a9c0" d="M12 22c2.7 0 5-.9 6.7-2.4l-3.3-2.5c-.9.6-2 1-3.4 1-2.6 0-4.8-1.8-5.6-4.1H3v2.6A10 10 0 0 0 12 22z"/><path fill="#7d7d98" d="M6.4 14a6 6 0 0 1 0-3.9V7.4H3a10 10 0 0 0 0 9.2z"/><path fill="#d2d2e0" d="M12 6c1.5 0 2.8.5 3.8 1.5l2.9-2.9A10 10 0 0 0 3 7.4L6.4 10C7.2 7.7 9.4 6 12 6z"/></svg> Google</button>
        <button class="btn btn-outline"><svg class="size-4" viewBox="0 0 24 24" fill="currentColor"><path d="M16.4 12.6c0-2.6 2.1-3.8 2.2-3.9a4.8 4.8 0 0 0-3.8-2c-1.6-.2-3.1.9-3.9.9s-2.1-.9-3.4-.9a5 5 0 0 0-4.2 2.6c-1.8 3.1-.5 7.7 1.3 10.2.9 1.2 1.9 2.6 3.2 2.6 1.3-.1 1.8-.8 3.3-.8s2 .8 3.4.8 2.2-1.3 3-2.5a10.4 10.4 0 0 0 1.4-2.8 4.4 4.4 0 0 1-2.5-4.2zM13.8 5c.7-.9 1.2-2 1-3.2-1 0-2.2.7-2.9 1.5a4.1 4.1 0 0 0-1 3.1c1.1.1 2.2-.6 2.9-1.4z"/></svg> Apple</button>
    </div>
    <div class="my-6 flex items-center gap-3 text-xs text-ink-500"><span class="h-px flex-1 bg-white/[0.06]"></span>or with email<span class="h-px flex-1 bg-white/[0.06]"></span></div>

    <form method="POST" action="{{ route('login.attempt') }}" class="space-y-4" x-data="{ show: false }">
        @csrf
        <div><label class="label" for="email">Email</label><input id="email" type="email" name="email" value="{{ old('email') }}" class="input" placeholder="you@yourcompany.com" autocomplete="email" required>@error('email')<p class="mt-1 text-xs text-coral-400">{{ $message }}</p>@enderror</div>
        <div>
            <div class="flex justify-between"><label class="label" for="password">Password</label><a href="#" class="text-xs text-volt-300 hover:text-white">Forgot?</a></div>
            <div class="relative">
                <input id="password" :type="show ? 'text' : 'password'" type="password" name="password" class="input pr-10" placeholder="••••••••" autocomplete="current-password">
                <button type="button" @click="show = !show" class="absolute right-3 top-1/2 -translate-y-1/2 text-ink-400 hover:text-white" aria-label="Toggle password"><x-icon name="eye" class="size-4"/></button>
            </div>
        </div>
        <label class="flex items-center gap-2 text-sm text-ink-400"><input type="checkbox" name="remember" value="1" class="size-4 accent-volt-500"> Keep me signed in</label>
        <button class="btn btn-primary w-full !py-3">Sign in</button>
    </form>
    <p class="mt-6 text-center text-sm text-ink-400">No account yet? <a href="{{ route('pricing') }}" class="text-white hover:text-volt-300">Buy a license</a> or <a href="{{ route('register') }}" class="text-white hover:text-volt-300">create an account</a></p>
    <p class="mt-3 text-center text-xs text-ink-500">Local test accounts are listed in the README.</p>
</x-layouts.auth>
