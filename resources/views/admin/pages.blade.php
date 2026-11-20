<x-layouts.app area="admin" title="Legal pages" subtitle="Terms & conditions, privacy, refunds and the license agreement. Written in Markdown.">
    <x-slot:eyebrow>Admin · Storefront</x-slot:eyebrow>
    <div class="card divide-y divide-white/[0.05]">
        @foreach ($pages as $page)
            <div class="flex flex-wrap items-center gap-4 p-5">
                <span class="grid size-10 place-items-center rounded-xl bg-white/[0.04] text-volt-300"><x-icon name="file" class="size-4"/></span>
                <div class="flex-1">
                    <div class="font-medium text-white">{{ $page->title }}</div>
                    <div class="text-xs text-ink-500"><span class="font-mono">/legal/{{ $page->slug }}</span> · updated {{ local_time($page->updated_at)->diffForHumans() }}</div>
                </div>
                <span class="badge {{ $page->published ? 'badge-mint' : 'badge-ink' }}">{{ $page->published ? 'Published' : 'Hidden' }}</span>
                <a href="{{ route('legal', $page->slug) }}" target="_blank" class="btn btn-ghost btn-sm">View</a>
                <a href="{{ route('admin.pages.edit', $page) }}" class="btn btn-outline btn-sm">Edit</a>
            </div>
        @endforeach
    </div>
</x-layouts.app>
