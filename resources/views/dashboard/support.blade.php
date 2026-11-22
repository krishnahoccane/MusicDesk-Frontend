@php use App\Models\SupportTicket; use App\Services\Support\SupportDesk; @endphp
<x-layouts.app title="Support" subtitle="Get help from the {{ setting('branding.product_name', 'LabelDesk') }} engineering team.">
    <x-slot:actions>
        <button class="btn btn-primary btn-sm" @click="$dispatch('open-ticket')"><x-icon name="plus" class="size-3.5"/> New ticket</button>
    </x-slot:actions>

    {{-- Coverage --}}
    @if ($licenses)
        <div class="grid gap-4 md:grid-cols-3">
            @foreach ($licenses as $l)
                @php $expired = $l['support_expired'] ?? false; @endphp
                <div class="card card-pad">
                    <div class="flex items-center justify-between">
                        <span class="font-mono text-xs text-ink-400">{{ $l['id'] }}</span>
                        <span class="badge {{ $expired ? 'badge-amber' : 'badge-mint' }}">{{ $expired ? 'Support expired' : 'Covered' }}</span>
                    </div>
                    <div class="mt-3 font-medium text-white">{{ $l['product'] }} {{ $l['tier'] }} <span class="text-ink-500">· {{ $l['type'] }}</span></div>
                    <div class="mt-1 text-xs text-ink-400">
                        {{ $l['plan_code'] === 'business' ? 'Priority · first reply within 1 business day' : 'Email · first reply within 2 business days' }}
                    </div>
                    <div class="mt-3 text-xs {{ $expired ? 'text-amber-glow' : 'text-ink-500' }}">{{ $l['support_until'] ? ($expired ? 'Ended ' : 'Until ').$l['support_until'] : '' }}</div>
                </div>
            @endforeach
        </div>
    @endif

    <div class="mt-4 grid gap-4 xl:grid-cols-3" x-data="{ s: 'all' }">
        {{-- Tickets --}}
        <div class="card xl:col-span-2">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b hairline p-4">
                <div class="flex gap-1 rounded-xl border hairline bg-white/[0.02] p-1 text-[13px]">
                    @foreach (['all' => 'All', 'open' => 'Open', 'awaiting' => 'Awaiting you', 'resolved' => 'Resolved'] as $k => $l)
                        <button @click="s = '{{ $k }}'" :class="s === '{{ $k }}' ? 'bg-white/10 text-white' : 'text-ink-400 hover:text-white'" class="rounded-lg px-3 py-1.5 font-medium transition">
                            {{ $l }}@if ($k !== 'all' && ($n = $tickets->where('status', $k)->count())) <span class="ml-0.5 text-ink-500">{{ $n }}</span>@endif
                        </button>
                    @endforeach
                </div>
            </div>
            <div class="divide-y divide-white/[0.04]">
                @forelse ($tickets as $t)
                    <a href="{{ route('dashboard.support', ['t' => $t->number]) }}" x-show="s === 'all' || s === '{{ $t->status }}'"
                       class="flex w-full items-center gap-4 px-5 py-4 text-left transition hover:bg-white/[0.025] {{ $active?->is($t) ? 'bg-white/[0.04]' : '' }}">
                        <span class="grid size-9 shrink-0 place-items-center rounded-xl {{ ['open' => 'bg-volt-500/15 text-volt-300', 'awaiting' => 'bg-amber-glow/15 text-amber-glow', 'resolved' => 'bg-mint-500/15 text-mint-400'][$t->status] }}">
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-sm font-medium text-white">{{ $t->subject }}</div>
                            <div class="mt-0.5 text-xs text-ink-500"><span class="font-mono">{{ $t->number }}</span>@if ($t->license) · <span class="font-mono">{{ $t->license->number }}</span>@endif · {{ $t->public_messages_count }} {{ Str::plural('message', $t->public_messages_count) }}</div>
                        </div>
                        <div class="hidden text-right sm:block">
                            <span class="badge {{ $t->badge() }}">{{ $t->statusLabel() }}</span>
                            <div class="mt-1 text-[11px] text-ink-500">{{ $t->last_reply_at?->diffForHumans() }}</div>
                        </div>
                    </a>
                @empty
                    <div class="px-5 py-14 text-center">
                        <span class="mx-auto grid size-12 place-items-center rounded-2xl bg-white/[0.04] text-ink-400"><x-icon name="headphones" class="size-5"/></span>
                        <div class="mt-4 text-sm font-medium text-white">No tickets yet</div>
                        <p class="mt-1 text-xs text-ink-500">Stuck on an install, update or license? Open a ticket and the team replies here and by email.</p>
                        <button class="btn btn-primary btn-sm mt-5" @click="$dispatch('open-ticket')"><x-icon name="plus" class="size-3.5"/> New ticket</button>
                    </div>
                @endforelse
            </div>
        </div>

        {{-- Conversation --}}
        <div class="card flex min-h-[420px] flex-col" id="conversation">
            @if (! $active)
                <div class="grid flex-1 place-items-center p-8 text-center">
                    <div>
                        <span class="mx-auto grid size-12 place-items-center rounded-2xl bg-white/[0.04] text-ink-400"><x-icon name="headphones" class="size-5"/></span>
                        <div class="mt-4 text-sm font-medium text-white">Select a ticket</div>
                        <p class="mt-1 text-xs text-ink-500">The conversation opens here. Replies also arrive by email.</p>
                    </div>
                </div>
            @else
                <div class="flex flex-1 flex-col">
                    <div class="border-b hairline p-5">
                        <div class="flex items-center justify-between gap-3">
                            <div class="font-mono text-[11px] text-ink-500">{{ $active->number }}{{ $active->license ? ' · '.$active->license->number : '' }}{{ $active->domain ? ' · '.$active->domain : '' }}</div>
                            <span class="badge {{ $active->badge() }}">{{ $active->statusLabel() }}</span>
                        </div>
                        <div class="mt-1 font-medium text-white">{{ $active->subject }}</div>
                        <div class="mt-0.5 text-[11px] text-ink-500">{{ $active->categoryLabel() }} · {{ SupportTicket::PRIORITIES[$active->priority] ?? $active->priority }} priority</div>
                    </div>
                    <div class="max-h-[520px] flex-1 space-y-4 overflow-y-auto p-5 text-sm" x-init="$el.scrollTop = $el.scrollHeight">
                        @foreach ($active->publicMessages as $m)
                            <div class="max-w-[85%] rounded-2xl p-3 {{ $m->from_staff ? 'ml-auto rounded-tr-sm bg-volt-500/15 text-ink-100' : 'rounded-tl-sm bg-white/[0.05] text-ink-200' }}">
                                <div class="whitespace-pre-line break-words">{{ $m->body }}</div>
                                @foreach ($m->attachments ?? [] as $f)
                                    <a href="{{ route('dashboard.support.attachment', [$active, $m->id, $f['id']]) }}" class="mt-2 flex items-center gap-1.5 text-[12px] text-volt-300 hover:text-white"><x-icon name="file" class="size-3.5"/> {{ $f['name'] }} <span class="text-ink-500">{{ number_format($f['size'] / 1024, 0) }} KB</span></a>
                                @endforeach
                                <div class="mt-1 text-[10px] {{ $m->from_staff ? 'text-ink-400' : 'text-ink-500' }}">{{ $m->from_staff ? $m->author_name.' · '.setting('branding.product_name', 'LabelDesk').' Support' : ($m->user_id === auth()->id() ? 'You' : $m->author_name) }} · <span title="{{ local_time($m->created_at)->format('M d, Y H:i') }}">{{ $m->created_at->diffForHumans() }}</span></div>
                            </div>
                        @endforeach
                    </div>
                    @if ($active->status === SupportTicket::RESOLVED)
                        <div class="flex items-center justify-between gap-3 border-t hairline p-4 text-xs text-ink-400">
                            <span>Resolved {{ $active->resolved_at?->diffForHumans() }}. Still a problem?</span>
                            <form method="POST" action="{{ route('dashboard.support.status', $active) }}">@csrf<input type="hidden" name="status" value="open"><button class="btn btn-outline btn-sm">Reopen</button></form>
                        </div>
                    @else
                        <form method="POST" action="{{ route('dashboard.support.reply', $active) }}" enctype="multipart/form-data" class="border-t hairline p-3" x-data="{ files: [] }">
                            @csrf
                            <textarea name="body" class="input min-h-20 text-sm" placeholder="Write a reply…" required maxlength="20000">{{ old('body') }}</textarea>
                            @error('body')<p class="mt-1 text-xs text-coral-400">{{ $message }}</p>@enderror
                            @error('files.*')<p class="mt-1 text-xs text-coral-400">{{ $message }}</p>@enderror
                            <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                                <div class="flex items-center gap-1">
                                    <label class="btn btn-ghost btn-sm cursor-pointer" title="Attach files"><x-icon name="upload" class="size-3.5"/> <span x-text="files.length ? files.length + ' file(s)' : 'Attach'"></span><input type="file" name="files[]" multiple class="sr-only" @change="files = [...$event.target.files]"></label>
                                    <button type="submit" form="resolve-{{ $active->id }}" class="btn btn-ghost btn-sm"><x-icon name="check" class="size-3.5"/> Mark resolved</button>
                                </div>
                                <button class="btn btn-primary btn-sm">Send reply</button>
                            </div>
                        </form>
                        <form id="resolve-{{ $active->id }}" method="POST" action="{{ route('dashboard.support.status', $active) }}">@csrf<input type="hidden" name="status" value="resolved"></form>
                    @endif
                </div>
            @endif
        </div>
    </div>

    <x-modal event="open-ticket" title="New support ticket" subtitle="Tell us which install it’s about and we’ll reply by email and here." width="max-w-xl">
        <form method="POST" action="{{ route('dashboard.support.store') }}" enctype="multipart/form-data" class="mt-6 space-y-4"
              x-data="{ lic: @js(old('license', $licenses[0]['uid'] ?? '')), files: [], domains: @js(collect($licenses)->mapWithKeys(fn ($l) => [$l['uid'] => collect($l['installations'])->pluck('domain')])) }">
            @csrf
            <div class="grid gap-4 sm:grid-cols-2">
                <div><label class="label" for="t-license">License</label>
                    <select id="t-license" name="license" class="input" x-model="lic">
                        @foreach ($licenses as $l)<option value="{{ $l['uid'] }}">{{ $l['id'] }} · {{ $l['tier'] }}</option>@endforeach
                        <option value="">Not about a license</option>
                    </select></div>
                <div><label class="label" for="t-domain">Domain</label>
                    <select id="t-domain" name="domain" class="input">
                        <option value="">Not about one domain</option>
                        <template x-for="d in (domains[lic] || [])" :key="d"><option :value="d" x-text="d"></option></template>
                    </select></div>
                <div><label class="label" for="t-category">Category</label>
                    <select id="t-category" name="category" class="input">@foreach (SupportTicket::CATEGORIES as $k => $v)<option value="{{ $k }}" @selected(old('category') === $k)>{{ $v }}</option>@endforeach</select></div>
                <div><label class="label" for="t-priority">Priority</label>
                    <select id="t-priority" name="priority" class="input">@foreach (SupportTicket::PRIORITIES as $k => $v)<option value="{{ $k }}" @selected(old('priority', 'normal') === $k)>{{ $v }}</option>@endforeach</select></div>
            </div>
            <div><label class="label" for="t-subject">Subject</label><input id="t-subject" name="subject" value="{{ old('subject') }}" class="input" placeholder="Short summary of the issue" required maxlength="200"></div>
            <div><label class="label" for="t-body">Details</label><textarea id="t-body" name="body" class="input min-h-32" placeholder="Steps to reproduce, error messages, {{ setting('branding.product_name', 'LabelDesk') }} version…" required maxlength="20000">{{ old('body') }}</textarea></div>
            <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-dashed border-white/15 px-4 py-3 text-sm text-ink-400 transition hover:border-volt-500/50">
                <x-icon name="upload" class="text-volt-300"/>
                <span x-text="files.length ? files.map(f => f.name).join(', ') : 'Attach logs or screenshots (up to {{ SupportDesk::MAX_FILES }} files, 10 MB each)'"></span>
                <input type="file" name="files[]" class="sr-only" multiple @change="files = [...$event.target.files]">
            </label>
            @if ($errors->any() && old('subject'))<p class="text-xs text-coral-400">{{ $errors->first() }}</p>@endif
            <div class="flex justify-end gap-2"><button type="button" class="btn btn-ghost" @click="open = false">Cancel</button><button class="btn btn-primary">Create ticket</button></div>
        </form>
    </x-modal>
    @if ($errors->hasAny(['subject', 'category', 'priority']) || ($errors->any() && old('subject')))
        <div x-data x-init="$nextTick(() => $dispatch('open-ticket'))"></div>
    @endif
</x-layouts.app>
