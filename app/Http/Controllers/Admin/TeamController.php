<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\TeamInviteMail;
use App\Models\AdminRole;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Throwable;

/**
 * Administration team: members (invite, change role, remove) and roles.
 *
 * Guard rails: nobody changes or removes themselves; only Owners manage Owners,
 * edit roles, or hand out roles with permissions the inviter doesn't have; the last
 * Owner can't be demoted or removed.
 */
class TeamController extends Controller
{
    public const INVITE_DAYS = 7;

    public function index(Request $request): View
    {
        $members = User::query()->with(['adminRole', 'inviter'])->where('is_admin', true)
            ->orderByRaw('invite_accepted_at is null')->orderBy('name')->get();

        return view('admin.team', [
            'members' => $members,
            'roles' => AdminRole::query()->withCount('members')->orderBy('sort')->orderBy('name')->get(),
            'me' => $request->user()->load('adminRole'),
        ]);
    }

    // ------------------------------------------------------------ members

    public function invite(Request $request): RedirectResponse
    {
        $data = $request->validateWithBag('invite', [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'admin_role_id' => ['required', 'exists:admin_roles,id'],
        ]);
        $email = strtolower(trim($data['email']));
        $role = AdminRole::findOrFail($data['admin_role_id']);
        $this->requireMayAssign($request->user(), $role);

        $existing = User::query()->where('email', $email)->first();
        if ($existing?->is_admin) {
            return back()->withErrors(['email' => 'That person is already on the team.'], 'invite')->withInput();
        }
        if ($existing) {
            // Customer accounts stay separate from staff access.
            return back()->withErrors(['email' => 'That email belongs to a customer account. Use a separate work email for team access.'], 'invite')->withInput();
        }

        $member = new User(['name' => $data['name'], 'email' => $email, 'password' => Str::random(64), 'role' => 'owner']);
        $member->forceFill(['is_admin' => true, 'admin_role_id' => $role->id, 'invited_by' => $request->user()->id, 'invited_at' => now()])->save();
        $link = $this->sendInvite($member);

        return back()->with(['toast' => "Invitation sent to {$email}", 'invite_link' => $link, 'invite_email' => $email]);
    }

    public function resendInvite(Request $request, User $member): RedirectResponse
    {
        $this->requireMayManage($request->user(), $member);
        abort_unless($member->invitePending(), 422, 'This member already joined.');
        $member->forceFill(['invited_at' => now()])->save();   // older links stop working
        $link = $this->sendInvite($member);

        return back()->with(['toast' => "Invitation re-sent to {$member->email}", 'invite_link' => $link, 'invite_email' => $member->email]);
    }

    public function updateMember(Request $request, User $member): RedirectResponse
    {
        $this->requireMayManage($request->user(), $member);
        $data = $request->validate(['admin_role_id' => ['required', 'exists:admin_roles,id']]);
        $role = AdminRole::findOrFail($data['admin_role_id']);
        $this->requireMayAssign($request->user(), $role);

        if ($member->isOwner() && ! $role->is_owner && $this->ownerCount() <= 1) {
            return back()->with(['toast' => 'There must always be at least one Owner.', 'toast_tone' => 'danger']);
        }
        $member->forceFill(['admin_role_id' => $role->id])->save();

        return back()->with('toast', "{$member->name} is now {$role->name}");
    }

    public function removeMember(Request $request, User $member): RedirectResponse
    {
        $this->requireMayManage($request->user(), $member);
        if ($member->isOwner() && $this->ownerCount() <= 1) {
            return back()->with(['toast' => 'You can’t remove the last Owner.', 'toast_tone' => 'danger']);
        }

        DB::transaction(function () use ($member) {
            if ($member->organisation_id) {
                // Also a customer: keep the customer account, drop staff access.
                $member->forceFill(['is_admin' => false, 'admin_role_id' => null])->save();
            } else {
                $member->delete();
            }
            if (config('session.driver') === 'database') {
                DB::table(config('session.table', 'sessions'))->where('user_id', $member->id)->delete();   // sign them out everywhere
            }
        });

        return back()->with('toast', "{$member->name} was removed from the team");
    }

    // ------------------------------------------------------------ roles (Owners only)

    public function storeRole(Request $request): RedirectResponse
    {
        $this->requireOwner($request->user());
        $data = $this->validateRole($request);
        AdminRole::create([...$data, 'sort' => (int) AdminRole::max('sort') + 1]);

        return back()->with('toast', "Role {$data['name']} created");
    }

    public function updateRole(Request $request, AdminRole $role): RedirectResponse
    {
        $this->requireOwner($request->user());
        abort_if($role->is_owner, 403, 'The Owner role always has full access.');
        $role->update($this->validateRole($request, $role));

        return back()->with('toast', "Role {$role->name} saved");
    }

    public function destroyRole(Request $request, AdminRole $role): RedirectResponse
    {
        $this->requireOwner($request->user());
        abort_if($role->is_owner, 403, 'The Owner role can’t be deleted.');
        if ($role->members()->exists()) {
            return back()->with(['toast' => "Move the members of {$role->name} to another role first.", 'toast_tone' => 'danger']);
        }
        $role->delete();

        return back()->with('toast', "Role {$role->name} deleted");
    }

    // ------------------------------------------------------------ invitation acceptance (signed link)

    public function showAccept(Request $request, User $member): View|RedirectResponse
    {
        if (! $this->inviteValid($request, $member)) {
            return redirect()->route('login')->withErrors(['email' => 'That invitation link has expired or was already used. Ask the person who invited you to send a new one.']);
        }

        return view('auth.team-accept', ['member' => $member->load('adminRole', 'inviter')]);
    }

    public function accept(Request $request, User $member): RedirectResponse
    {
        abort_unless($this->inviteValid($request, $member), 403, 'This invitation has expired or was already used.');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()],
        ]);

        $member->forceFill([
            'name' => $data['name'], 'password' => $data['password'],
            'invite_accepted_at' => now(), 'email_verified_at' => now(), 'last_login_at' => now(),
        ])->save();

        Auth::logout();
        Auth::login($member);
        $request->session()->regenerate();

        return redirect()->route('admin.dashboard')->with('toast', 'Welcome to the team, '.Str::before($member->name, ' ').'.');
    }

    // ------------------------------------------------------------ helpers

    private function sendInvite(User $member): string
    {
        $link = URL::temporarySignedRoute('team.accept', now()->addDays(self::INVITE_DAYS), ['member' => $member->id, 'v' => $member->invited_at->timestamp]);
        try {
            Mail::to($member->email)->send(new TeamInviteMail($member->load('adminRole', 'inviter'), $link, self::INVITE_DAYS));
        } catch (Throwable $e) {
            Log::warning('Team invite email failed', ['email' => $member->email, 'error' => $e->getMessage()]);
        }

        return $link;
    }

    private function inviteValid(Request $request, User $member): bool
    {
        return $member->is_admin && $member->invitePending() && (int) $request->query('v') === $member->invited_at?->timestamp;
    }

    private function validateRole(Request $request, ?AdminRole $role = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('admin_roles', 'name')->ignore($role?->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => [Rule::in(array_keys(AdminRole::PERMISSIONS))],
        ], ['permissions.required' => 'Pick at least one area.']);

        return ['name' => $data['name'], 'description' => $data['description'] ?? null, 'permissions' => array_values(array_unique($data['permissions']))];
    }

    private function ownerCount(): int
    {
        return User::query()->where('is_admin', true)->whereNotNull('invite_accepted_at')
            ->whereHas('adminRole', fn ($q) => $q->where('is_owner', true))->count();
    }

    /** Can $actor give out this role? Owners: any. Others: only non-Owner roles within their own permissions. */
    public static function mayAssign(User $actor, AdminRole $role): bool
    {
        if ($actor->isOwner()) {
            return true;
        }

        return ! $role->is_owner && array_diff($role->permissionKeys(), $actor->adminRole?->permissionKeys() ?? []) === [];
    }

    /** Can $actor change or remove $member? Never themselves; Owners only by Owners. */
    public static function mayManage(User $actor, User $member): bool
    {
        return $actor->id !== $member->id && $member->is_admin
            && ($actor->isOwner() || ($member->adminRole && self::mayAssign($actor, $member->adminRole)));
    }

    private function requireMayAssign(User $actor, AdminRole $role): void
    {
        abort_unless(self::mayAssign($actor, $role), 403, 'You can only give out roles within your own access. Ask an Owner.');
    }

    private function requireMayManage(User $actor, User $member): void
    {
        abort_unless(self::mayManage($actor, $member), 403, $actor->id === $member->id ? 'You can’t change your own access.' : 'Only an Owner can change this member.');
    }

    private function requireOwner(User $actor): void
    {
        abort_unless($actor->isOwner(), 403, 'Only Owners can edit roles.');
    }
}
