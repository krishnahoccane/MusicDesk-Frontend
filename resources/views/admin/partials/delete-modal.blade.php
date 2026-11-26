{{--
    Permanent delete with a typed confirmation. Opens on window event $event; the event detail may carry
    {action, expected, label} (lists), otherwise $action / $expected are used. $warning: what goes with it.
    $invoices: show "also delete its invoices" (orders).
--}}
@php $what = $what ?? 'number'; @endphp
@error('confirm')
    <div class="mb-4 flex items-center gap-3 rounded-xl border border-coral-500/30 bg-coral-500/10 p-3 text-sm text-coral-300"><x-icon name="alert" class="size-4"/> Not deleted: {{ $message }}</div>
@enderror
<x-modal :event="$event" :title="$title" width="max-w-md">
    <form method="POST" :action="data.action || @js($action ?? '')" class="mt-4 space-y-4" x-data="{ typed: '' }" x-effect="open && (typed = '')">
        @csrf @method('DELETE')
        <div class="rounded-xl border border-coral-500/25 bg-coral-500/[0.06] p-3 text-sm text-ink-200">
            <b class="text-coral-400">This can’t be undone.</b> {{ $warning }}
        </div>
        @if ($invoices ?? false)
            <label class="flex items-center gap-2 text-sm text-ink-200"><input type="checkbox" name="with_invoices" value="1" class="size-4 accent-coral-500"> Also delete its invoices (otherwise they are kept for your accounts)</label>
        @endif
        <div>
            <label class="label">Type <span class="font-mono text-white" x-text="data.expected || @js($expected ?? '')"></span> to confirm</label>
            <input name="confirm" x-model="typed" class="input font-mono" autocomplete="off" required>
        </div>
        <div class="flex justify-end gap-2">
            <button type="button" class="btn btn-ghost" @click="open = false">Cancel</button>
            <button class="btn btn-danger" :disabled="typed.trim() !== (data.expected || @js($expected ?? ''))"><x-icon name="trash" class="size-3.5"/> Delete permanently</button>
        </div>
    </form>
</x-modal>
