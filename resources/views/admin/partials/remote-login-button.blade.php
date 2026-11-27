{{-- "Sign in to site" as its LabelDesk Super Admin. Expects $domain, optional $license (License). Opens a new tab. --}}
@if (auth()->user()?->canAdmin('remote_login'))
    <form method="POST" action="{{ route('admin.remote-login') }}" target="_blank" class="inline"
          onsubmit="return confirm('Sign in to {{ $domain }} as its Super Admin? It opens in a new tab and is recorded{{ isset($license) && $license ? ' in the license history' : '' }} and on the site.')">
        @csrf
        <input type="hidden" name="domain" value="{{ $domain }}">
        @if (isset($license) && $license)<input type="hidden" name="license" value="{{ $license->uuid }}">@endif
        <button class="btn btn-outline btn-sm {{ ($compact ?? false) ? '!p-1.5' : '' }}" title="Sign in to {{ $domain }} as Super Admin" aria-label="Sign in to {{ $domain }} as Super Admin">
            <x-icon name="arrow-up-right" class="size-3.5"/>@unless ($compact ?? false) Sign in as admin @endunless
        </button>
    </form>
@endif
