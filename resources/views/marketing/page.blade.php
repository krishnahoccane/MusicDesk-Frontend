<x-layouts.marketing :title="$page->title">
    <article class="mx-auto max-w-3xl px-4 pt-16 sm:px-6">
        <div class="eyebrow">Legal · updated {{ local_time($page->updated_at)->format('F j, Y') }}</div>
        <div class="prose-md mt-6">{!! Str::markdown($page->body ?? '', ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}</div>
        <div class="mt-12 flex flex-wrap gap-2 border-t hairline pt-6 text-sm">
            @foreach (config('site.pages') as $slug => $t)
                <a href="{{ route('legal', $slug) }}" class="rounded-lg px-3 py-1.5 {{ $slug === $page->slug ? 'bg-white/10 text-white' : 'text-ink-400 hover:text-white' }}">{{ $t }}</a>
            @endforeach
        </div>
    </article>
</x-layouts.marketing>
