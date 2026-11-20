<div class="mt-10 divide-y divide-white/[0.06] rounded-3xl border hairline bg-ink-900/40" x-data="{ open: 0 }">
    @foreach ($faqs as $i => [$q, $a])
        <div>
            <button @click="open = open === {{ $i }} ? null : {{ $i }}" class="flex w-full items-center justify-between gap-6 px-6 py-5 text-left">
                <span class="font-medium text-white">{{ $q }}</span>
                <span class="grid size-7 shrink-0 place-items-center rounded-full border hairline text-ink-300 transition" :class="open === {{ $i }} && 'rotate-45 bg-white text-ink-950'">
                    <x-icon name="plus" class="size-3.5" stroke="2.25"/>
                </span>
            </button>
            <div x-show="open === {{ $i }}" x-collapse x-cloak>
                <p class="px-6 pb-6 text-[15px] leading-relaxed text-ink-400">{{ $a }}</p>
            </div>
        </div>
    @endforeach
</div>
