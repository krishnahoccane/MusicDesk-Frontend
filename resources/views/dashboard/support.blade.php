@php use App\Support\Demo; @endphp
<x-layouts.app title="Support" subtitle="Get help from the LabelDesk engineering team.">
    <x-slot:actions>
        <button class="btn btn-primary btn-sm" @click="$dispatch('open-ticket')"><x-icon name="plus" class="size-3.5"/> New ticket</button>
    </x-slot:actions>

    {{-- Coverage --}}
    <div class="grid gap-4 md:grid-cols-3">
        @foreach ($licenses as $l)
            @php $expired = $l['support_expired'] ?? false; @endphp
            <div class="card card-pad">
                <div class="flex items-center justify-between">
                    <span class="font-mono text-xs text-ink-400">{{ $l['id'] }}</span>
                    <span class="badge {{ $expired ? 'badge-amber' : 'badge-mint' }}">{{ $expired ? 'Support expired' : 'Covered' }}</span>
                </div>
                <div class="mt-3 font-medium text-white">LabelDesk {{ $l['tier'] }} <span class="text-ink-500">· {{ $l['type'] }}</span></div>
                <div class="mt-1 text-xs text-ink-400">
                    {{ $l['tier'] === 'Business' ? 'Priority · first reply within 1 business day' : 'Email · first reply within 2 business days' }}
                </div>
                <div class="mt-3 text-xs {{ $expired ? 'text-amber-glow' : 'text-ink-500' }}">{{ $expired ? 'Ended' : 'Until' }} {{ $l['support_until'] }}</div>
            </div>
        @endforeach
    </div>

    <div class="mt-4 grid gap-4 xl:grid-cols-3" x-data="{ s: 'all', active: null, tickets: {{ json_encode($tickets) }} }">
        {{-- Tickets --}}
        <div class="card xl:col-span-2">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b hairline p-4">
                <div class="flex gap-1 rounded-xl border hairline bg-white/[0.02] p-1 text-[13px]">
                    @foreach (['all' => 'All', 'open' => 'Open', 'awaiting' => 'Awaiting you', 'resolved' => 'Resolved'] as $k => $l)
                        <button @click="s = '{{ $k }}'" :class="s === '{{ $k }}' ? 'bg-white/10 text-white' : 'text-ink-400 hover:text-white'" class="rounded-lg px-3 py-1.5 font-medium transition">{{ $l }}</button>
                    @endforeach
                </div>
            </div>
            <div class="divide-y divide-white/[0.04]">
                <template x-for="t in tickets.filter(t => s === 'all' || t.status === s)" :key="t.id">
                    <button @click="active = t" class="flex w-full items-center gap-4 px-5 py-4 text-left transition hover:bg-white/[0.025]" :class="active?.id === t.id && 'bg-white/[0.04]'">
                        <span class="grid size-9 shrink-0 place-items-center rounded-xl"
                              :class="{ 'bg-volt-500/15 text-volt-300': t.status === 'open', 'bg-amber-glow/15 text-amber-glow': t.status === 'awaiting', 'bg-mint-500/15 text-mint-400': t.status === 'resolved' }">
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-sm font-medium text-white" x-text="t.subject"></div>
                            <div class="mt-0.5 text-xs text-ink-500"><span class="font-mono" x-text="t.id"></span> · <span class="font-mono" x-text="t.license"></span> · <span x-text="t.replies + ' replies'"></span></div>
                        </div>
                        <div class="hidden text-right sm:block">
                            <span class="badge" :class="{ 'badge-volt': t.status === 'open', 'badge-amber': t.status === 'awaiting', 'badge-mint': t.status === 'resolved' }" x-text="t.status === 'awaiting' ? 'Awaiting you' : t.status.charAt(0).toUpperCase() + t.status.slice(1)"></span>
                            <div class="mt-1 text-[11px] text-ink-500" x-text="t.updated"></div>
                        </div>
                    </button>
                </template>
            </div>
        </div>

        {{-- Conversation --}}
        <div class="card flex min-h-[420px] flex-col">
            <template x-if="!active">
                <div class="grid flex-1 place-items-center p-8 text-center">
                    <div>
                        <span class="mx-auto grid size-12 place-items-center rounded-2xl bg-white/[0.04] text-ink-400"><x-icon name="headphones" class="size-5"/></span>
                        <div class="mt-4 text-sm font-medium text-white">Select a ticket</div>
                        <p class="mt-1 text-xs text-ink-500">Or browse the docs. Most install and update questions are answered there.</p>
                        <div class="mt-5 flex justify-center gap-2">
                            <a href="#" class="btn btn-outline btn-sm"><x-icon name="file" class="size-3.5"/> Docs</a>
                            <a href="#" class="btn btn-outline btn-sm"><x-icon name="zap" class="size-3.5"/> Status</a>
                        </div>
                    </div>
                </div>
            </template>
            <template x-if="active">
                <div class="flex flex-1 flex-col">
                    <div class="border-b hairline p-5">
                        <div class="font-mono text-[11px] text-ink-500" x-text="active.id + ' · ' + active.license"></div>
                        <div class="mt-1 font-medium text-white" x-text="active.subject"></div>
                    </div>
                    <div class="flex-1 space-y-4 overflow-y-auto p-5 text-sm">
                        <div class="max-w-[85%] rounded-2xl rounded-tl-sm bg-white/[0.05] p-3 text-ink-200">After running the updater on portal.sidequest.audio we get a 500 on /admin/statements. Staging worked fine.<div class="mt-1 text-[10px] text-ink-500">You · 2h ago</div></div>
                        <div class="ml-auto max-w-[85%] rounded-2xl rounded-tr-sm bg-volt-500/15 p-3 text-ink-100">Thanks, {{ Str::before(auth()->user()->name, ' ') }}. 4.3.0 adds a new queue for FX rates. Could you run <code class="rounded bg-black/30 px-1 font-mono text-[11px]">php artisan queue:restart</code> and clear the config cache? That fixes it in every case we’ve seen so far.<div class="mt-1 text-[10px] text-ink-400">Priya · LabelDesk Support · 35 min ago</div></div>
                    </div>
                    <form class="border-t hairline p-3" @submit.prevent="toast('Reply sent'); $el.reset()">
                        <textarea class="input min-h-20 text-sm" placeholder="Write a reply…"></textarea>
                        <div class="mt-2 flex items-center justify-between">
                            <button type="button" class="btn btn-ghost btn-sm" @click="active.status = 'resolved'; toast('Ticket marked as resolved')"><x-icon name="check" class="size-3.5"/> Mark resolved</button>
                            <button class="btn btn-primary btn-sm">Send reply</button>
                        </div>
                    </form>
                </div>
            </template>
        </div>
    </div>

    <x-modal event="open-ticket" title="New support ticket" subtitle="Tell us which install it’s about and we’ll reply by email and here." width="max-w-xl">
        <form class="mt-6 space-y-4" @submit.prevent="open = false; toast('Ticket TCK-4822 created')">
            <div class="grid gap-4 sm:grid-cols-2">
                <div><label class="label">License</label><select class="input">@foreach ($licenses as $l)<option>{{ $l['id'] }} · {{ $l['tier'] }}</option>@endforeach</select></div>
                <div><label class="label">Domain</label><select class="input">@foreach ($licenses as $l)@foreach ($l['installations'] as $i)<option>{{ $i['domain'] }}</option>@endforeach @endforeach</select></div>
                <div><label class="label">Category</label><select class="input"><option>Installation</option><option>Updating</option><option>Branding & theme</option><option>Bug report</option><option>Licensing & billing</option><option>Feature request</option></select></div>
                <div><label class="label">Priority</label><select class="input"><option>Normal</option><option>Low</option><option>High: production is down</option></select></div>
            </div>
            <div><label class="label">Subject</label><input class="input" placeholder="Short summary of the issue" required></div>
            <div><label class="label">Details</label><textarea class="input min-h-32" placeholder="Steps to reproduce, error messages, LabelDesk version…" required></textarea></div>
            <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-dashed border-white/15 px-4 py-3 text-sm text-ink-400 transition hover:border-volt-500/50"><x-icon name="upload" class="text-volt-300"/> Attach logs or screenshots<input type="file" class="sr-only" multiple></label>
            <div class="flex justify-end gap-2"><button type="button" class="btn btn-ghost" @click="open = false">Cancel</button><button class="btn btn-primary">Create ticket</button></div>
        </form>
    </x-modal>
</x-layouts.app>
