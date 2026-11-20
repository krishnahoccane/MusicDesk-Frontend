@php use App\Http\Controllers\Admin\ReleaseAdminController as R; @endphp
<x-layouts.app area="admin" title="Releases" subtitle="LabelDesk versions customers can download. Packages are stored privately and only served to licenses whose updates period covers the release.">
    <x-slot:eyebrow>Admin · Licensing</x-slot:eyebrow>
    <x-slot:actions><button class="btn btn-primary btn-sm" @click="$dispatch('open-release', {})"><x-icon name="upload" class="size-3.5"/> New release</button></x-slot:actions>

    <div class="space-y-3">
        @forelse ($releases as $r)
            <form method="POST" action="{{ route('admin.releases.update', $r) }}" enctype="multipart/form-data" class="card" x-data="{ open: false }">
                @csrf @method('PUT')
                <div class="flex flex-wrap items-center gap-4 p-5">
                    <span class="font-mono text-lg font-semibold text-white">v{{ $r->version }}</span>
                    <span class="badge {{ $r->channel === 'beta' ? 'badge-amber' : 'badge-ink' }}">{{ $r->channel }}</span>
                    <span class="badge {{ $r->published ? 'badge-mint' : 'badge-ink' }}">{{ $r->published ? 'Published' : 'Hidden' }}</span>
                    <span class="text-xs text-ink-500">{{ $r->released_at->format('M d, Y') }}</span>
                    <span class="text-xs {{ $r->hasFile() ? 'text-ink-400' : 'text-amber-glow' }}">{{ $r->hasFile() ? number_format($r->size_bytes / 1048576, 1).' MB · sha256 '.substr($r->sha256, 0, 12).'…' : 'No package uploaded' }}</span>
                    <button type="button" class="btn btn-outline btn-sm ml-auto" @click="open = !open" x-text="open ? 'Close' : 'Edit'"></button>
                </div>
                <div x-show="open" x-collapse x-cloak class="border-t hairline">
                    @include('admin.partials.release-fields', ['r' => $r])
                    <div class="flex justify-between border-t hairline px-5 py-4">
                        <button type="submit" form="del-release-{{ $r->id }}" class="btn btn-danger btn-sm"><x-icon name="trash" class="size-3.5"/> Delete</button>
                        <button class="btn btn-primary btn-sm">Save v{{ $r->version }}</button>
                    </div>
                </div>
            </form>
            <form id="del-release-{{ $r->id }}" method="POST" action="{{ route('admin.releases.destroy', $r) }}" onsubmit="return confirm('Delete v{{ $r->version }} and its package?')">@csrf @method('DELETE')</form>
        @empty
            <div class="card py-12 text-center text-ink-500">No releases yet.</div>
        @endforelse
    </div>

    <x-modal event="open-release" title="New release" width="max-w-2xl">
        <form method="POST" action="{{ route('admin.releases.store') }}" enctype="multipart/form-data" class="-mx-6 mt-2">
            @csrf
            @include('admin.partials.release-fields', ['r' => null])
            <div class="flex justify-end gap-2 border-t hairline px-6 pt-4"><button type="button" class="btn btn-ghost" @click="open = false">Cancel</button><button class="btn btn-primary">Save release</button></div>
        </form>
    </x-modal>
</x-layouts.app>
