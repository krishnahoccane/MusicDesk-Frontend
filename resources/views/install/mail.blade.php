@php
    $enc = old('encryption', $env->get('MAIL_SCHEME') === 'smtps' ? 'ssl' : 'tls');
    $from = $env->get('MAIL_FROM_ADDRESS');
@endphp
<x-layouts.installer :step="$step" :steps="$steps" :progress="$progress" title="Email (SMTP)" subtitle="Used for invoices, receipts, password resets and license notices. Use the SMTP details from your email provider (Google Workspace, Microsoft 365, Zoho, Amazon SES, Postmark, Mailgun…).">
    <form method="POST" action="{{ route('install.mail') }}" class="card card-pad space-y-5"
          x-data="{ enc: @js($enc), port: @js(old('port', $env->get('MAIL_PORT') && $env->get('MAIL_PORT') !== '2525' ? $env->get('MAIL_PORT') : ($enc === 'ssl' ? 465 : 587))), busy: false }"
          x-init="$watch('enc', e => port = e === 'ssl' ? 465 : 587)" @submit="busy = true">
        @csrf
        <div class="grid gap-4 sm:grid-cols-[1fr_120px]">
            <div><label class="label" for="host">SMTP host</label><input id="host" name="host" class="input" value="{{ old('host', $env->get('MAIL_HOST') !== '127.0.0.1' ? $env->get('MAIL_HOST') : '') }}" placeholder="smtp.example.com" required></div>
            <div><label class="label" for="port">Port</label><input id="port" name="port" type="number" x-model="port" class="input font-mono" required></div>
        </div>

        <div>
            <span class="label">Encryption</span>
            <div class="grid grid-cols-2 gap-2">
                <label class="cursor-pointer"><input type="radio" name="encryption" value="tls" x-model="enc" class="peer sr-only" @checked($enc === 'tls')>
                    <span class="block rounded-xl border border-white/[0.08] px-3 py-2.5 text-sm text-ink-300 transition peer-checked:border-volt-500/60 peer-checked:bg-volt-500/10 peer-checked:text-white">STARTTLS <span class="text-xs text-ink-500">· usually port 587</span></span></label>
                <label class="cursor-pointer"><input type="radio" name="encryption" value="ssl" x-model="enc" class="peer sr-only" @checked($enc === 'ssl')>
                    <span class="block rounded-xl border border-white/[0.08] px-3 py-2.5 text-sm text-ink-300 transition peer-checked:border-volt-500/60 peer-checked:bg-volt-500/10 peer-checked:text-white">SSL/TLS <span class="text-xs text-ink-500">· usually port 465</span></span></label>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div><label class="label" for="username">Username</label><input id="username" name="username" class="input" value="{{ old('username', $env->get('MAIL_USERNAME')) }}" autocomplete="off"></div>
            <div><label class="label" for="password">Password / app password</label><input id="password" name="password" type="password" class="input" autocomplete="new-password"></div>
            <div><label class="label" for="from_address">“From” address</label><input id="from_address" name="from_address" type="email" class="input" value="{{ old('from_address', $from && $from !== 'hello@example.com' ? $from : '') }}" placeholder="billing@yourdomain.com" required></div>
            <div><label class="label" for="from_name">“From” name</label><input id="from_name" name="from_name" class="input" value="{{ old('from_name', in_array($env->get('MAIL_FROM_NAME'), [null, '', '${APP_NAME}', 'Laravel'], true) ? 'MusicDesk' : $env->get('MAIL_FROM_NAME')) }}" required></div>
        </div>

        <div class="rounded-xl border hairline bg-white/[0.02] p-4">
            <label class="label !mt-0" for="test_to">Send a test email to <span class="text-ink-500">(optional, recommended)</span></label>
            <input id="test_to" name="test_to" type="email" class="input" value="{{ old('test_to') }}" placeholder="you@yourdomain.com">
            <p class="mt-1.5 text-xs text-ink-500">The installer always checks it can sign in to the SMTP server. A test email also confirms delivery.</p>
        </div>

        <div class="flex items-center justify-between border-t hairline pt-5">
            <a href="{{ route('install.step', 'redis') }}" class="btn btn-ghost">Back</a>
            <button class="btn btn-primary" :disabled="busy">
                <span x-show="!busy">Test & save <x-icon name="arrow-right" class="inline size-4"/></span><span x-show="busy" x-cloak>Checking the SMTP server…</span>
            </button>
        </div>
    </form>
</x-layouts.installer>
