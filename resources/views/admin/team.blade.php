@php
    use App\Http\Controllers\Admin\TeamController as Team;
    use App\Models\AdminRole;
    $isOwner = $me->isOwner();
    $assignable = $roles->filter(fn ($r) => Team::mayAssign($me, $r));
@endphp
<x-layouts.app area="admin" title="Team & roles" subtitle="Who can sign in to the site admin, and what each role can do.">
    <x-slot:eyebrow>Admin · Administration</x-slot:eyebrow>
    <x-slot:actions>
        @if ($isOwner)<button class="btn btn-outline btn-sm" @click="$dispatch('open-role', { id: null, name: '', description: '', permissions: [] })"><x-icon name="plus" class="size-3.5"/> New role</button>@endif
        <button class="btn btn-primary btn-sm" @click="$dispatch('open-invite')"><x-icon name="user-plus" class="size-3.5"/> Invite member</button>
    </x-slot:actions>

    @if (session('invite_link'))
        <div class="mb-4 rounded-2xl border border-mint-500/25 bg-mint-500/[0.06] p-4" x-data="{ copied: false }">
            <div class="flex items-start gap-3">
                <x-icon name="mail" class="mt-0.5 size-4 shrink-0 text-mint-400"/>
                <div class="min-w-0 flex-1">
                    <div class="text-sm font-medium text-white">Invitation emailed to {{ session('invite_email') }}</div>
                    <p class="mt-0.5 text-xs text-ink-400">If email isn’t set up yet, send them this link yourself. It works once and expires in {{ Team::INVITE_DAYS }} days. It won’t be shown again.</p>
                    <div class="mt-2 flex gap-2">
                        <input readonly value="{{ session('invite_link') }}" class="input !py-2 font-mono text-[11px]" @focus="$el.select()">
                        <button type="button" class="btn btn-outline btn-sm shrink-0" @click="navigator.clipboard.writeText(@js(session('invite_link'))); copied = true; toast('Invite link copied')"><x-icon name="copy" class="size-3.5"/> <span x-text="copied ? 'Copied' : 'Copy'"></span></button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Members --}}
    <section class="card">
        <div class="flex items-center justify-between border-b hairline p-4">
            <div>
                <h2 class="font-semibold text-white">Members</h2>
                <p class="mt-0.5 text-xs text-ink-500">{{ $members->whereNotNull('invite_accepted_at')->count() }} active · {{ $members->whereNull('invite_accepted_at')->count() }} invited</p>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>Member</th><th>Role</th><th>Status</th><th>Last sign-in</th><th>Invited by</th><th class="text-right"></th></tr></thead>
                <tbody>
                    @foreach ($members as $m)
                        @php $manage = Team::mayManage($me, $m); @endphp
                        <tr>
                            <td>
                                <div class="flex items-center gap-3">
                                    <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-gradient-to-br from-volt-500 to-flare-500 text-xs font-semibold text-white {{ $m->invitePending() ? 'opacity-40' : '' }}">{{ $m->initials() }}</span>
                                    <div class="min-w-0">
                                        <div class="truncate text-ink-100">{{ $m->name }} @if ($m->id === $me->id)<span class="text-xs text-ink-500">(you)</span>@endif</div>
                                        <div class="truncate text-[11px] text-ink-500">{{ $m->email }}@if ($m->organisation_id) · also a customer @endif</div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                @if ($manage)
                                    <form method="POST" action="{{ route('admin.team.members.update', $m) }}">
                                        @csrf @method('PUT')
                                        <select name="admin_role_id" class="input !w-auto !py-1.5 text-[13px]" onchange="this.form.submit()" aria-label="Role for {{ $m->name }}">
                                            @foreach ($assignable as $r)<option value="{{ $r->id }}" @selected($m->admin_role_id === $r->id)>{{ $r->name }}</option>@endforeach
                                        </select>
                                    </form>
                                @else
                                    <span class="badge {{ $m->adminRole?->is_owner ? 'badge-flare' : 'badge-volt' }}">{{ $m->adminRole?->name ?? 'No role' }}</span>
                                @endif
                            </td>
                            <td>
                                @if ($m->invitePending())
                                    @php $expired = $m->invited_at->lt(now()->subDays(Team::INVITE_DAYS)); @endphp
                                    <span class="badge badge-dot {{ $expired ? 'badge-coral' : 'badge-amber' }}">{{ $expired ? 'Invite expired' : 'Invited' }}</span>
                                    <div class="mt-1 text-[11px] text-ink-500">Sent {{ $m->invited_at->diffForHumans() }}</div>
                                @else
                                    <span class="badge badge-dot badge-mint">Active</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap text-ink-300">{{ $m->last_login_at ? $m->last_login_at->diffForHumans() : '—' }}</td>
                            <td class="text-ink-400">{{ $m->inviter?->name ?? '—' }}</td>
                            <td class="text-right">
                                @if ($manage)
                                    <div class="flex justify-end gap-1.5">
                                        @if ($m->invitePending())
                                            <form method="POST" action="{{ route('admin.team.members.resend', $m) }}">@csrf<button class="btn btn-outline btn-sm">Resend invite</button></form>
                                        @endif
                                        <form method="POST" action="{{ route('admin.team.members.remove', $m) }}" onsubmit="return confirm(@js($m->invitePending() ? 'Cancel the invitation for '.$m->email.'?' : 'Remove '.$m->name.' from the team? They’ll be signed out and lose access to the site admin.'))">
                                            @csrf @method('DELETE')
                                            <button class="btn btn-danger btn-sm">{{ $m->invitePending() ? 'Cancel invite' : 'Remove' }}</button>
                                        </form>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    {{-- Roles --}}
    <section class="mt-6">
        <div class="mb-3 flex items-end justify-between">
            <div>
                <h2 class="font-semibold text-white">Roles</h2>
                <p class="mt-0.5 text-xs text-ink-500">Every member has the dashboard; a role adds the areas below. {{ $isOwner ? 'Only Owners can edit roles.' : 'Ask an Owner to change roles.' }}</p>
            </div>
        </div>
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($roles as $r)
                <div class="card card-pad flex flex-col">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="flex items-center gap-2 font-medium text-white">{{ $r->name }} @if ($r->is_owner)<x-icon name="lock" class="size-3.5 text-flare-400"/>@endif</div>
                            <p class="mt-1 text-xs text-ink-400">{{ $r->description }}</p>
                        </div>
                        <span class="shrink-0 text-xs text-ink-500">{{ $r->members_count }} {{ Str::plural('member', $r->members_count) }}</span>
                    </div>
                    <div class="mt-4 flex flex-1 flex-wrap content-start gap-1.5">
                        @if ($r->is_owner)
                            <span class="badge badge-flare">Everything</span>
                        @else
                            @foreach ($r->permissionKeys() as $p)<span class="badge badge-ink">{{ AdminRole::PERMISSIONS[$p][0] }}</span>@endforeach
                        @endif
                    </div>
                    @if ($isOwner && ! $r->is_owner)
                        <div class="mt-4 flex gap-2 border-t hairline pt-3">
                            <button class="btn btn-outline btn-sm" @click="$dispatch('open-role', @js(['id' => $r->id, 'name' => $r->name, 'description' => $r->description, 'permissions' => $r->permissionKeys()]))">Edit</button>
                            <form method="POST" action="{{ route('admin.team.roles.destroy', $r) }}" onsubmit="return confirm(@js('Delete the '.$r->name.' role?'))">@csrf @method('DELETE')
                                <button class="btn btn-ghost btn-sm" @disabled($r->members_count > 0) title="{{ $r->members_count ? 'Move its members to another role first' : 'Delete role' }}"><x-icon name="trash" class="size-3.5"/> Delete</button></form>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </section>

    {{-- Permission matrix --}}
    <section class="card mt-6">
        <div class="border-b hairline p-4"><h2 class="font-semibold text-white">What each role can do</h2></div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>Area</th>@foreach ($roles as $r)<th class="text-center">{{ $r->name }}</th>@endforeach</tr></thead>
                <tbody>
                    <tr><td><div class="text-ink-100">Dashboard</div><div class="text-[11px] text-ink-500">Overview and revenue</div></td>@foreach ($roles as $r)<td class="text-center"><x-icon name="check" class="mx-auto size-4 text-mint-400"/></td>@endforeach</tr>
                    @foreach (AdminRole::PERMISSIONS as $key => [$label, $hint])
                        <tr>
                            <td><div class="text-ink-100">{{ $label }}</div><div class="text-[11px] text-ink-500">{{ $hint }}</div></td>
                            @foreach ($roles as $r)
                                <td class="text-center">@if ($r->allows($key))<x-icon name="check" class="mx-auto size-4 text-mint-400"/>@else<span class="text-ink-500">—</span>@endif</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    {{-- Invite --}}
    <x-modal event="open-invite" title="Invite a team member" subtitle="They get an email with a link to set their password." width="max-w-lg">
        <form method="POST" action="{{ route('admin.team.invite') }}" class="mt-5 space-y-4">
            @csrf
            <div><label class="label" for="i-name">Name</label><input id="i-name" name="name" value="{{ old('name') }}" class="input" required maxlength="120">
                @error('name', 'invite')<p class="mt-1 text-xs text-coral-400">{{ $message }}</p>@enderror</div>
            <div><label class="label" for="i-email">Work email</label><input id="i-email" type="email" name="email" value="{{ old('email') }}" class="input" required maxlength="190">
                @error('email', 'invite')<p class="mt-1 text-xs text-coral-400">{{ $message }}</p>@enderror</div>
            <div><label class="label" for="i-role">Role</label>
                <select id="i-role" name="admin_role_id" class="input">
                    @foreach ($assignable as $r)<option value="{{ $r->id }}" @selected((string) old('admin_role_id', $assignable->firstWhere('name', 'Support agent')?->id) === (string) $r->id)>{{ $r->name }}: {{ $r->is_owner ? 'everything' : collect($r->permissionKeys())->map(fn ($p) => AdminRole::PERMISSIONS[$p][0])->implode(', ') }}</option>@endforeach
                </select>
                @unless ($isOwner)<p class="mt-1 text-xs text-ink-500">You can give out roles within your own access.</p>@endunless
            </div>
            <div class="flex justify-end gap-2"><button type="button" class="btn btn-ghost" @click="open = false">Cancel</button><button class="btn btn-primary">Send invitation</button></div>
        </form>
    </x-modal>
    @if ($errors->invite->any())<div x-data x-init="$nextTick(() => $dispatch('open-invite'))"></div>@endif

    {{-- Create / edit role --}}
    @if ($isOwner)
        <x-modal event="open-role" title="Role" width="max-w-xl">
            <form method="POST" :action="data.id ? @js(url('admin/team/roles')) + '/' + data.id : @js(route('admin.team.roles.store'))" class="mt-5 space-y-4">
                @csrf
                <template x-if="data.id"><input type="hidden" name="_method" value="PUT"></template>
                <div><label class="label" for="r-name">Name</label><input id="r-name" name="name" x-model="data.name" class="input" required maxlength="60" placeholder="e.g. Release manager"></div>
                <div><label class="label" for="r-desc">Description <span class="text-ink-500">(optional)</span></label><input id="r-desc" name="description" x-model="data.description" class="input" maxlength="255"></div>
                <fieldset>
                    <legend class="label">Areas</legend>
                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach (AdminRole::PERMISSIONS as $key => [$label, $hint])
                            <label class="flex cursor-pointer items-start gap-3 rounded-xl border hairline p-3 transition hover:border-white/10">
                                <input type="checkbox" name="permissions[]" value="{{ $key }}" x-model="data.permissions" class="mt-0.5 size-4 accent-volt-500">
                                <span><span class="block text-sm text-white">{{ $label }}</span><span class="block text-[11px] text-ink-500">{{ $hint }}</span></span>
                            </label>
                        @endforeach
                    </div>
                    @error('permissions')<p class="mt-1 text-xs text-coral-400">{{ $message }}</p>@enderror
                    <p class="mt-2 text-[11px] text-amber-glow" x-show="(data.permissions || []).includes('team')">Members with “Team & roles” can invite and remove people (within their own access).</p>
                </fieldset>
                <div class="flex justify-end gap-2"><button type="button" class="btn btn-ghost" @click="open = false">Cancel</button><button class="btn btn-primary" x-text="data.id ? 'Save role' : 'Create role'">Save role</button></div>
            </form>
        </x-modal>
        @if ($errors->hasAny(['name', 'permissions']))<div x-data x-init="$nextTick(() => $dispatch('open-role', { id: null, name: @js(old('name', '')), description: @js(old('description', '')), permissions: @js(old('permissions', [])) }))"></div>@endif
    @endif
</x-layouts.app>
