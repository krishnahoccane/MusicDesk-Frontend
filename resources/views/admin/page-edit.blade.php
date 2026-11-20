<x-layouts.app area="admin" :title="'Edit: '.$page->title" subtitle="Markdown: # Heading, ## Subheading, **bold**, [link](https://…), - list items. Raw HTML is stripped.">
    <x-slot:eyebrow><a href="{{ route('admin.pages') }}" class="hover:text-white">Admin · Legal pages</a></x-slot:eyebrow>
    <x-slot:actions><a href="{{ route('legal', $page->slug) }}" target="_blank" class="btn btn-outline btn-sm">View live <x-icon name="arrow-up-right" class="size-3.5"/></a></x-slot:actions>

    <form method="POST" action="{{ route('admin.pages.update', $page) }}" class="grid gap-4 xl:grid-cols-2" x-data="{ body: @js($page->body ?? '') }">
        @csrf @method('PUT')
        <div class="card card-pad space-y-4">
            <div><label class="label" for="title">Title</label><input id="title" name="title" value="{{ $page->title }}" class="input" required></div>
            <div><label class="label" for="body">Content</label><textarea id="body" name="body" x-model="body" rows="24" class="input font-mono text-[13px] leading-relaxed"></textarea></div>
            <div class="flex items-center justify-between">
                <label class="flex items-center gap-2 text-sm text-ink-200"><input type="checkbox" name="published" value="1" @checked($page->published) class="size-4 accent-volt-500"> Published</label>
                <button class="btn btn-primary">Save page</button>
            </div>
        </div>
        <div class="card card-pad">
            <div class="eyebrow mb-4">Preview after saving</div>
            <div class="prose-md">{!! Str::markdown($page->body ?? '', ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}</div>
        </div>
    </form>
</x-layouts.app>
