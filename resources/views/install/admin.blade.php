<x-layouts.installer :step="$step" :steps="$steps" :progress="$progress" title="Create the super admin" subtitle="This account manages the storefront at /admin: branding, plans, payment gateways, currencies and invoices. Finishing this step completes the installation.">
    <form method="POST" action="{{ route('install.admin') }}" class="card card-pad space-y-5" x-data="{ pw: '', busy: false }" @submit="busy = true">
        @csrf
        <div class="grid gap-4 sm:grid-cols-2">
            <div><label class="label" for="site_name">Site name</label><input id="site_name" name="site_name" class="input" value="{{ old('site_name', 'MusicDesk') }}" required maxlength="60"></div>
            <div><label class="label" for="app_url">Site address (URL)</label><input id="app_url" name="app_url" type="url" class="input font-mono text-sm" value="{{ old('app_url', $detectedUrl) }}" required>
                <p class="mt-1 text-xs text-ink-500">Used in emails, invoices and payment callbacks. Use https:// on a live site.</p></div>
        </div>

        <div class="border-t hairline pt-5">
            <div class="grid gap-4 sm:grid-cols-2">
                <div><label class="label" for="name">Your name</label><input id="name" name="name" class="input" value="{{ old('name') }}" autocomplete="name" required></div>
                <div><label class="label" for="email">Email (you sign in with this)</label><input id="email" name="email" type="email" class="input" value="{{ old('email') }}" autocomplete="email" required></div>
                <div>
                    <label class="label" for="password">Password</label>
                    <input id="password" name="password" type="password" x-model="pw" class="input" autocomplete="new-password" minlength="12" required>
                    <div class="mt-2 flex gap-1">
                        <template x-for="i in 4"><span class="h-1 flex-1 rounded-full transition" :class="pw.length >= i * 4 ? (pw.length >= 16 ? 'bg-mint-400' : 'bg-amber-glow') : 'bg-white/10'"></span></template>
                    </div>
                    <p class="mt-1 text-xs text-ink-500">At least 12 characters, with letters and numbers.</p>
                </div>
                <div><label class="label" for="password_confirmation">Confirm password</label><input id="password_confirmation" name="password_confirmation" type="password" class="input" autocomplete="new-password" required></div>
            </div>
        </div>

        <label class="flex items-start gap-3 rounded-xl border hairline p-4 text-sm">
            <input type="hidden" name="production" value="0">
            <input type="checkbox" name="production" value="1" class="mt-0.5 size-4 accent-volt-500" @checked(old('production', '1') === '1')>
            <span><span class="block text-ink-100">This is a live (production) server</span>
                <span class="text-xs text-ink-500">Sets APP_ENV=production and turns debug mode off, so error details are never shown to visitors. Untick only for a local or staging copy.</span></span>
        </label>

        <div class="flex items-center justify-between border-t hairline pt-5">
            <a href="{{ route('install.step', 'mail') }}" class="btn btn-ghost">Back</a>
            <button class="btn btn-primary" :disabled="busy">
                <span x-show="!busy">Create admin & finish <x-icon name="check" class="inline size-4"/></span><span x-show="busy" x-cloak>Finishing…</span>
            </button>
        </div>
    </form>
</x-layouts.installer>
