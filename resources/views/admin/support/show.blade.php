@php use App\Models\SupportTicket; use App\Services\Support\SupportDesk; $org = $ticket->organisation; @endphp
<x-layouts.app area="admin" :title="$ticket->number">
    <x-slot:heading>{{ $ticket->subject }}</x-slot:heading>
    <x-slot:eyebrow><a href="{{ route('admin.support') }}" class="hover:text-white">Admin · Support</a> · {{ $ticket->number }}</x-slot:eyebrow>
    <x-slot:actions>
        <span class="badge badge-dot {{ $ticket->badge(true) }}">{{ $ticket->statusLabel(true) }}</span>
        @if ($ticket->overdue())<span class="badge badge-coral">Over {{ $ticket->slaHours() }} h reply target</span>@endif
    </x-slot:actions>

    <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_360px]">
        {{-- Conversation --}}
        <div class="space-y-4">
            <div class="card">
                <div class="space-y-4 p-5 text-sm">
                    @foreach ($ticket->messages as $m)
                        <div class="flex gap-3 {{ $m->from_staff ? 'flex-row-reverse' : '' }}">
                            <span class="grid size-8 shrink-0 place-items-center rounded-lg text-[11px] font-semibold {{ $m->from_staff ? 'bg-gradient-to-br from-volt-500 to-flare-500 text-white' : 'bg-white/[0.06] text-ink-200' }}">{{ collect(explode(' ', $m->author_name))->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('') }}</span>
                            <div class="max-w-[85%] rounded-2xl p-3 {{ $m->internal ? 'border border-dashed border-amber-glow/40 bg-amber-glow/[0.06] text-ink-100' : ($m->from_staff ? 'rounded-tr-sm bg-volt-500/15 text-ink-100' : 'rounded-tl-sm bg-white/[0.05] text-ink-200') }}">
                                <div class="mb-1 flex flex-wrap items-center gap-x-2 text-[11px] {{ $m->from_staff ? 'text-ink-400' : 'text-ink-500' }}">
                                    <span class="font-medium text-ink-200">{{ $m->author_name }}</span>
                                    <span>{{ $m->from_staff ? 'Support' : $org->name }}</span>
                                    @if ($m->internal)<span class="badge badge-amber !py-0 !text-[10px]">Internal note</span>@endif
                                    <span title="{{ local_time($m->created_at)->format('M d, Y H:i') }}">{{ $m->created_at->diffForHumans() }}</span>
                                </div>
                                <div class="whitespace-pre-line break-words">{{ $m->body }}</div>
                                @foreach ($m->attachments ?? [] as $f)
                                    <a href="{{ route('admin.support.attachment', [$ticket, $m->id, $f['id']]) }}" class="mt-2 flex items-center gap-1.5 text-[12px] text-volt-300 hover:text-white"><x-icon name="file" class="size-3.5"/> {{ $f['name'] }} <span class="text-ink-500">{{ number_format($f['size'] / 1024, 0) }} KB</span></a>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Reply --}}
            <form method="POST" action="{{ route('admin.support.reply', $ticket) }}" enctype="multipart/form-data" class="card card-pad space-y-3" x-data="{ internal: {{ old('internal') ? 'true' : 'false' }}, files: [] }">
                @csrf
                <div class="flex items-center justify-between gap-3">
                    <div class="inline-flex rounded-xl border hairline p-1 text-xs">
                        <button type="button" @click="internal = false" :class="! internal ? 'bg-white/10 text-white' : 'text-ink-400'" class="rounded-lg px-3 py-1.5">Reply to customer</button>
                        <button type="button" @click="internal = true" :class="internal ? 'bg-amber-glow/15 text-amber-glow' : 'text-ink-400'" class="rounded-lg px-3 py-1.5">Internal note</button>
                    </div>
                    <span class="text-[11px] text-ink-500" x-text="internal ? 'Only admins see this.' : 'Emailed to {{ $ticket->user?->email ?? $org->email }}'"></span>
                </div>
                <input type="hidden" name="internal" :value="internal ? 1 : 0">
                <textarea name="body" class="input min-h-36 text-sm" :class="internal && '!border-amber-glow/30'" required maxlength="20000" placeholder="Write your reply…">{{ old('body') }}</textarea>
                @error('body')<p class="text-xs text-coral-400">{{ $message }}</p>@enderror
                @error('files.*')<p class="text-xs text-coral-400">{{ $message }}</p>@enderror
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <label class="btn btn-ghost btn-sm cursor-pointer"><x-icon name="upload" class="size-3.5"/> <span x-text="files.length ? files.length + ' file(s)' : 'Attach'"></span><input type="file" name="files[]" multiple class="sr-only" @change="files = [...$event.target.files]"></label>
                    <div class="flex items-center gap-2">
                        <select name="then" class="input !w-auto !py-2 text-[13px]" x-show="! internal">
                            <option value="awaiting">Then: awaiting customer</option>
                            <option value="resolved">Then: resolved</option>
                            <option value="open">Then: keep open</option>
                        </select>
                        <button class="btn btn-primary btn-sm" x-text="internal ? 'Add note' : 'Send reply'">Send reply</button>
                    </div>
                </div>
            </form>
        </div>

        {{-- Aside --}}
        <aside class="space-y-4">
            <form method="POST" action="{{ route('admin.support.update', $ticket) }}" class="card card-pad space-y-3">
                @csrf @method('PUT')
                <h2 class="font-semibold text-white">Ticket</h2>
                <div><label class="label" for="s-status">Status</label>
                    <select id="s-status" name="status" class="input">@foreach ([SupportTicket::OPEN => 'Needs reply', SupportTicket::AWAITING => 'Awaiting customer', SupportTicket::RESOLVED => 'Resolved'] as $k => $v)<option value="{{ $k }}" @selected($ticket->status === $k)>{{ $v }}</option>@endforeach</select></div>
                <div class="grid grid-cols-2 gap-3">
                    <div><label class="label" for="s-priority">Priority</label>
                        <select id="s-priority" name="priority" class="input">@foreach (['low' => 'Low', 'normal' => 'Normal', 'high' => 'High'] as $k => $v)<option value="{{ $k }}" @selected($ticket->priority === $k)>{{ $v }}</option>@endforeach</select></div>
                    <div><label class="label" for="s-category">Category</label>
                        <select id="s-category" name="category" class="input">@foreach (SupportTicket::CATEGORIES as $k => $v)<option value="{{ $k }}" @selected($ticket->category === $k)>{{ $v }}</option>@endforeach</select></div>
                </div>
                <div><label class="label" for="s-assignee">Assigned to</label>
                    <select id="s-assignee" name="assigned_to" class="input"><option value="">Nobody</option>@foreach ($staff as $u)<option value="{{ $u->id }}" @selected($ticket->assigned_to === $u->id)>{{ $u->name }}{{ $u->id === auth()->id() ? ' (you)' : '' }}</option>@endforeach</select></div>
                <button class="btn btn-outline btn-sm w-full">Save</button>
                <p class="text-[11px] text-ink-500">Opened {{ local_time($ticket->created_at)->format('M d, Y H:i') }} by {{ $ticket->user?->name ?? 'a former user' }}.</p>
            </form>

            <div class="card card-pad space-y-3 text-sm">
                <h2 class="font-semibold text-white">Customer</h2>
                <div>
                    <a href="{{ route('admin.customers.show', $org) }}" class="font-medium text-white hover:text-volt-300">{{ $org->name }}</a>
                    <div class="text-xs text-ink-400">{{ $org->email }}</div>
                </div>
                @if ($ticket->license)
                    @php $l = $ticket->license; $st = $l->displayStatus(); @endphp
                    <div class="rounded-xl border hairline p-3">
                        <div class="flex items-center justify-between"><a href="{{ route('admin.licenses.show', $l) }}" class="font-mono text-xs text-white hover:text-volt-300">{{ $l->number }}</a><span class="badge badge-dot {{ \App\Support\Demo::statusBadge($st) }}">{{ $st }}</span></div>
                        <div class="mt-1 text-xs text-ink-400">{{ $l->plan->name }} · {{ ucfirst($l->billing_type) }}</div>
                        <div class="mt-1 text-xs {{ $l->supportExpired() ? 'text-amber-glow' : 'text-ink-500' }}">Support {{ $l->supportExpired() ? 'ended' : 'until' }} {{ $l->support_until ? local_time($l->support_until)->format('M d, Y') : '—' }}</div>
                        @if ($ticket->domain)
                            @php $act = $l->activations->firstWhere('domain', $ticket->domain); @endphp
                            <div class="mt-2 border-t hairline pt-2 text-xs text-ink-400"><span class="font-mono text-ink-200">{{ $ticket->domain }}</span>
                                @if ($act) · v{{ $act->version ?? '?' }} · PHP {{ $act->php_version ?? '?' }} · {{ $act->isOnline() ? 'online' : 'last seen '.($act->last_check_in_at?->diffForHumans() ?? 'never') }}@endif
                            </div>
                        @endif
                    </div>
                @elseif ($ticket->domain)
                    <div class="text-xs text-ink-400">Domain: <span class="font-mono text-ink-200">{{ $ticket->domain }}</span></div>
                @endif
                @if ($history->isNotEmpty())
                    <div>
                        <div class="eyebrow mb-1.5 !text-[10px]">Other tickets</div>
                        @foreach ($history as $h)
                            <a href="{{ route('admin.support.show', $h) }}" class="flex items-center justify-between gap-2 rounded-lg px-2 py-1.5 text-xs hover:bg-white/[0.03]"><span class="truncate text-ink-200">{{ $h->subject }}</span><span class="badge {{ $h->badge(true) }} !text-[10px]">{{ $h->statusLabel(true) }}</span></a>
                        @endforeach
                    </div>
                @endif
            </div>
        </aside>
    </div>
</x-layouts.app>
