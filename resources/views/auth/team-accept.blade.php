<x-layouts.auth title="Join the team">
    <div class="eyebrow mb-2">{{ setting('branding.site_name', 'MusicDesk') }} · Administration</div>
    <h1 class="display text-3xl">Join the admin team</h1>
    <p class="mt-2 text-sm text-ink-400">{{ $member->inviter?->name ?? 'An Owner' }} invited <span class="text-white">{{ $member->email }}</span> as <span class="badge badge-volt">{{ $member->adminRole?->name }}</span>. Choose your name and a password.</p>

    <form method="POST" action="{{ request()->fullUrl() }}" class="mt-8 space-y-4" x-data="{ show: false }">
        @csrf
        <div><label class="label" for="name">Your name</label><input id="name" name="name" value="{{ old('name', $member->name) }}" class="input" required maxlength="120" autocomplete="name">@error('name')<p class="mt-1 text-xs text-coral-400">{{ $message }}</p>@enderror</div>
        <div>
            <label class="label" for="password">Password</label>
            <div class="relative">
                <input id="password" :type="show ? 'text' : 'password'" type="password" name="password" class="input pr-10" required autocomplete="new-password" minlength="12">
                <button type="button" @click="show = !show" class="absolute right-3 top-1/2 -translate-y-1/2 text-ink-400 hover:text-white" aria-label="Toggle password"><x-icon name="eye" class="size-4"/></button>
            </div>
            <p class="mt-1 text-xs text-ink-500">At least 12 characters, with letters and numbers.</p>
            @error('password')<p class="mt-1 text-xs text-coral-400">{{ $message }}</p>@enderror
        </div>
        <div><label class="label" for="password_confirmation">Confirm password</label><input id="password_confirmation" :type="show ? 'text' : 'password'" type="password" name="password_confirmation" class="input" required autocomplete="new-password"></div>
        <button class="btn btn-primary w-full !py-3">Accept and sign in</button>
    </form>
</x-layouts.auth>
