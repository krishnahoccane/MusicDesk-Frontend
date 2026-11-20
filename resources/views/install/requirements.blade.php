<x-layouts.installer :step="$step" :steps="$steps" :progress="$progress" title="Check the prerequisites" subtitle="Everything marked required must pass before you continue. Recommended items are optional.">
    <div class="space-y-4">
        @foreach ($groups as $group => $items)
            @php $failed = collect($items)->where('required', true)->where('ok', false)->count(); @endphp
            <section class="card overflow-hidden">
                <div class="flex items-center justify-between border-b hairline px-5 py-3.5">
                    <h2 class="font-semibold text-white">{{ $group }}</h2>
                    <span class="badge {{ $failed ? 'badge-coral' : 'badge-mint' }}">{{ $failed ? $failed.' to fix' : 'All good' }}</span>
                </div>
                <ul class="divide-y divide-white/[0.04]">
                    @foreach ($items as $item)
                        <li class="flex items-start gap-3 px-5 py-3">
                            <span class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full {{ $item['ok'] ? 'bg-mint-500/15 text-mint-400' : ($item['required'] ? 'bg-coral-500/15 text-coral-400' : 'bg-amber-glow/15 text-amber-glow') }}">
                                <x-icon :name="$item['ok'] ? 'check' : ($item['required'] ? 'x' : 'alert')" class="size-3" stroke="3"/>
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2 text-sm">
                                    <span class="font-mono text-ink-100">{{ $item['label'] }}</span>
                                    @unless ($item['required'])<span class="badge badge-ink !text-[10px]">recommended</span>@endunless
                                </div>
                                <div class="text-xs text-ink-500">{{ $item['detail'] }}</div>
                                @if (! $item['ok'] && $item['hint'])<div class="mt-1 text-xs text-amber-glow">{{ $item['hint'] }}</div>@endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    </div>

    <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('install.step', 'requirements') }}" class="btn btn-outline"><x-icon name="refresh" class="size-4"/> Check again</a>
        <form method="POST" action="{{ route('install.requirements') }}">
            @csrf
            <button class="btn btn-primary" @disabled(! $passes)>Continue to database <x-icon name="arrow-right" class="size-4"/></button>
        </form>
    </div>
</x-layouts.installer>
