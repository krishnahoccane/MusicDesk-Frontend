@php $k = $r?->id ?? 'new'; $req = $r?->requirements ?? []; @endphp
<div class="grid gap-4 p-5 sm:grid-cols-3 sm:px-6">
    <div><label class="label" for="ver_{{ $k }}">Version</label><input id="ver_{{ $k }}" name="version" value="{{ $r?->version }}" placeholder="4.3.0" class="input font-mono" required></div>
    <div><label class="label" for="ch_{{ $k }}">Channel</label><select id="ch_{{ $k }}" name="channel" class="input"><option value="stable">Stable</option><option value="beta" @selected($r?->channel === 'beta')>Beta</option></select></div>
    <div><label class="label" for="date_{{ $k }}">Release date</label><input id="date_{{ $k }}" type="date" name="released_at" value="{{ ($r?->released_at ?? now())->format('Y-m-d') }}" class="input" required></div>
    <div class="sm:col-span-3"><label class="label" for="notes_{{ $k }}">Changelog <span class="text-ink-500">(one per line: “new: …”, “improved: …”, “fixed: …”, “security: …”)</span></label>
        <textarea id="notes_{{ $k }}" name="notes" rows="5" class="input font-mono text-[13px]" placeholder="new: Multi-currency royalty statements&#10;fixed: CSV import ignored trailing whitespace">{{ \App\Http\Controllers\Admin\ReleaseAdminController::notesText($r?->notes) }}</textarea></div>
    <div><label class="label" for="php_{{ $k }}">Server requirement</label><input id="php_{{ $k }}" name="req_php" value="{{ $req['php'] ?? '' }}" placeholder="PHP 8.2+ · Nginx or Apache" class="input text-sm"></div>
    <div><label class="label" for="db_{{ $k }}">Database</label><input id="db_{{ $k }}" name="req_database" value="{{ $req['database'] ?? '' }}" placeholder="MySQL 8 / PostgreSQL 15" class="input text-sm"></div>
    <div><label class="label" for="ex_{{ $k }}">Extras</label><input id="ex_{{ $k }}" name="req_extras" value="{{ $req['extras'] ?? '' }}" placeholder="Redis, cron, 2 GB RAM" class="input text-sm"></div>
    <div class="sm:col-span-2"><label class="label" for="pkg_{{ $k }}">Package (.zip){{ $r?->hasFile() ? ': upload to replace' : '' }}</label>
        <input id="pkg_{{ $k }}" type="file" name="package" accept=".zip,application/zip" class="block w-full text-sm text-ink-300 file:mr-3 file:rounded-lg file:border-0 file:bg-white/10 file:px-3 file:py-1.5 file:text-sm file:text-white"></div>
    <label class="flex items-end gap-2 pb-2 text-sm text-ink-200"><input type="hidden" name="published" value="0"><input type="checkbox" name="published" value="1" class="size-4 accent-volt-500" @checked($r?->published ?? true)> Published</label>
</div>
