{{-- Add / move domain --}}
<x-modal event="open-domain" title="Add a domain" subtitle="Pre-approve a domain so LabelDesk activates there as soon as it checks in.">
    <form class="mt-6 space-y-4" x-data="{ env: 'production', domain: '', busy: false }"
          @submit.prevent="busy = true; try {
              const r = data.move
                  ? await portalApi(data.base + '/domains/' + encodeURIComponent(data.act) + '/move', 'POST', { new_domain: domain })
                  : await portalApi(data.base + '/domains', 'POST', { domain, environment: env });
              open = false; toast(r.message); setTimeout(() => location.reload(), 700);
          } catch (e) {} finally { busy = false }">
        <div x-show="data.move" class="rounded-xl border border-amber-glow/25 bg-amber-glow/[0.06] p-3 text-sm text-amber-glow">
            Moving from <b x-text="data.move"></b>. That install switches to read-only once the new domain checks in. Up to 3 moves per 30 days.
        </div>
        <div><label class="label" for="new_domain">Domain</label>
            <div class="flex"><span class="grid place-items-center rounded-l-xl border border-r-0 border-white/[0.08] bg-white/[0.03] px-3 text-sm text-ink-500">https://</span><input id="new_domain" x-model="domain" class="input !rounded-l-none" placeholder="portal.yourbrand.com" required></div></div>
        <div x-show="!data.move">
            <label class="label">Environment</label>
            <div class="grid grid-cols-2 gap-2">
                <button type="button" @click="env = 'production'" :class="env === 'production' ? 'border-volt-500/60 bg-volt-500/10 text-white' : 'border-white/[0.08] text-ink-300'" class="rounded-xl border px-3 py-2.5 text-left text-sm transition"><span class="block font-medium">Production</span><span class="text-xs text-ink-500">Uses a domain slot</span></button>
                <button type="button" @click="env = 'staging'" :class="env === 'staging' ? 'border-volt-500/60 bg-volt-500/10 text-white' : 'border-white/[0.08] text-ink-300'" class="rounded-xl border px-3 py-2.5 text-left text-sm transition"><span class="block font-medium">Staging / dev</span><span class="text-xs text-ink-500">Free · noindex enforced</span></button>
            </div>
        </div>
        <div class="flex justify-end gap-2 pt-2"><button type="button" class="btn btn-ghost" @click="open = false">Cancel</button><button class="btn btn-primary" :disabled="busy" x-text="busy ? 'Working…' : (data.move ? 'Move domain' : 'Add domain')"></button></div>
    </form>
</x-modal>


{{-- Offline activation --}}
<x-modal event="open-offline" title="Offline activation" subtitle="For servers that can’t reach the license server. Upload the request file from LabelDesk and download a signed activation file, valid for 365 days.">
    <form method="POST" enctype="multipart/form-data" class="mt-6 space-y-4" x-data="{ file: null, uid: null }"
          x-effect="uid = data.uid || uid || @js($licenses[0]['uid'] ?? null)"
          :action="@js(url('/dashboard/licenses')) + '/' + uid + '/offline-activation'" @submit="setTimeout(() => open = false, 300)">
        @csrf
        <div><label class="label" for="offline_license">License</label>
            <select id="offline_license" class="input" x-model="uid">@foreach ($licenses as $l)<option value="{{ $l['uid'] }}">{{ $l['id'] }} · {{ $l['tier'] }} {{ $l['type'] }}</option>@endforeach</select></div>
        <label class="grid cursor-pointer place-items-center rounded-2xl border border-dashed border-white/15 px-6 py-10 text-center transition hover:border-volt-500/50 hover:bg-volt-500/[0.04]">
            <x-icon name="upload" class="size-7 text-volt-300"/>
            <span class="mt-3 text-sm font-medium text-white" x-text="file ?? 'Choose activation-request.ldreq'"></span>
            <span class="mt-1 text-xs text-ink-500">In LabelDesk admin: Settings → License → Activate offline → Export request</span>
            <input type="file" name="request_file" class="sr-only" accept=".ldreq,.json,application/json" required @change="file = $event.target.files[0]?.name">
        </label>
        <div class="flex justify-end gap-2"><button type="button" class="btn btn-ghost" @click="open = false">Cancel</button><button class="btn btn-primary" :disabled="!file || !uid"><x-icon name="download" class="size-4"/> Download activation file</button></div>
    </form>
</x-modal>
{{-- Buy --}}
<x-modal event="open-buy" title="Buy a LabelDesk license" width="max-w-2xl">
    <div class="mt-6 grid gap-3 sm:grid-cols-3">
        @foreach ($tiers as $t)
            <a href="{{ route('checkout', $t->code) }}" class="group rounded-2xl border border-white/[0.08] p-4 transition hover:border-volt-500/50 hover:bg-volt-500/[0.05]">
                <div class="font-medium text-white">{{ $t['name'] }}</div>
                <div class="mt-1 text-xs text-ink-400">{{ $t['domains'] }} production {{ Str::plural('domain', $t['domains']) }}</div>
                <div class="mt-3 text-xl font-semibold text-white">{{ money($t->price_annual)['display'] }}<span class="text-xs font-normal text-ink-500">/yr</span></div>
                <div class="mt-3 flex items-center gap-1 text-xs text-volt-300">Continue <x-icon name="arrow-right" class="size-3 transition group-hover:translate-x-0.5"/></div>
            </a>
        @endforeach
    </div>
</x-modal>
