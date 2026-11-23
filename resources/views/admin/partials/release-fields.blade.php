@php $k = $r?->id ?? 'new'; $req = $r?->requirements ?? []; @endphp
<div class="grid gap-4 p-5 sm:grid-cols-3 sm:px-6" x-data="{ addon: '{{ $r?->addon_id }}' }">
    <div class="sm:col-span-3"><label class="label" for="prod_{{ $k }}">Product</label>
        <select id="prod_{{ $k }}" name="addon_id" class="input" x-model="addon">
            <option value="">{{ setting('branding.product_name', 'LabelDesk') }} (main application)</option>
            @foreach ($addons as $a)<option value="{{ $a->id }}" @selected((int) $r?->addon_id === $a->id)>{{ $a->name }} add-on{{ $a->active ? '' : ' (hidden)' }}</option>@endforeach
        </select>
        <p class="mt-1 text-[11px] text-ink-500" x-show="addon">Add-on releases can only be downloaded by customers who have that add-on, within its updates period. Their package must be the LabelDesk plugin ZIP: built and signed with <span class="font-mono">scripts/build-plugin.php</span>, or the plugin folder zipped unsigned (signed here on upload). plugin.json must name this add-on and version.</p>
        <p class="mt-1 text-[11px] text-ink-500" x-show="!addon">LabelDesk 4.3.3+ installs published stable releases over the air (Administration → Updates). Upload the signed <span class="font-mono">build/labeldesk-&lt;version&gt;.zip</span> from <span class="font-mono">scripts/package-release.php --key=…</span> as is, or an unsigned ZIP whose folder is <span class="font-mono">labeldesk-&lt;version&gt;</span> (signed here on upload). ZIPs with <span class="font-mono">.env</span>, logs, backups or links are refused.</p></div>
    <div><label class="label" for="ver_{{ $k }}">Version</label><input id="ver_{{ $k }}" name="version" value="{{ $r?->version }}" placeholder="4.3.0" class="input font-mono" required></div>
    <div><label class="label" for="ch_{{ $k }}">Channel</label><select id="ch_{{ $k }}" name="channel" class="input"><option value="stable">Stable</option><option value="beta" @selected($r?->channel === 'beta')>Beta</option></select></div>
    <div><label class="label" for="date_{{ $k }}">Release date</label><input id="date_{{ $k }}" type="date" name="released_at" value="{{ ($r?->released_at ?? now())->format('Y-m-d') }}" class="input" required></div>
    <div class="sm:col-span-3"><label class="label" for="notes_{{ $k }}">Changelog <span class="text-ink-500">(one per line: “new: …”, “improved: …”, “fixed: …”, “security: …”)</span></label>
        <textarea id="notes_{{ $k }}" name="notes" rows="5" class="input font-mono text-[13px]" placeholder="new: Multi-currency royalty statements&#10;fixed: CSV import ignored trailing whitespace">{{ \App\Http\Controllers\Admin\ReleaseAdminController::notesText($r?->notes) }}</textarea></div>
    <div><label class="label" for="php_{{ $k }}">Server requirement</label><input id="php_{{ $k }}" name="req_php" value="{{ $req['php'] ?? '' }}" placeholder="PHP 8.2+ · Nginx or Apache" class="input text-sm"></div>
    <div><label class="label" for="db_{{ $k }}">Database</label><input id="db_{{ $k }}" name="req_database" value="{{ $req['database'] ?? '' }}" placeholder="MySQL 8 / PostgreSQL 15" class="input text-sm"></div>
    <div><label class="label" for="ex_{{ $k }}">Extras</label><input id="ex_{{ $k }}" name="req_extras" value="{{ $req['extras'] ?? '' }}" placeholder="Redis, cron, 2 GB RAM" class="input text-sm"></div>
    <div><label class="label" for="minphp_{{ $k }}">Minimum PHP</label><input id="minphp_{{ $k }}" name="requires_php" value="{{ $r?->requires_php }}" placeholder="8.2" class="input font-mono text-sm">
        <p class="mt-1 text-[11px] text-ink-500">Shown to customers; LabelDesk won’t install the update on older PHP.</p></div>
    <div x-show="addon" x-cloak><label class="label" for="minld_{{ $k }}">Minimum LabelDesk</label><input id="minld_{{ $k }}" name="requires_labeldesk" value="{{ $r?->requires_labeldesk }}" placeholder="from plugin.json “requires”" class="input font-mono text-sm"></div>
    <div class="sm:col-span-2"><label class="label" for="pkg_{{ $k }}">Package (.zip){{ $r?->hasFile() ? ': upload to replace' : '' }}</label>
        <input id="pkg_{{ $k }}" type="file" name="package" accept=".zip,application/zip" class="block w-full text-sm text-ink-300 file:mr-3 file:rounded-lg file:border-0 file:bg-white/10 file:px-3 file:py-1.5 file:text-sm file:text-white"></div>
    <label class="flex items-end gap-2 pb-2 text-sm text-ink-200"><input type="hidden" name="published" value="0"><input type="checkbox" name="published" value="1" class="size-4 accent-volt-500" @checked($r?->published ?? true)> Published</label>
</div>
