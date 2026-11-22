<?php

namespace Tests\Feature;

use App\Mail\SupportTicketMail;
use App\Mail\TeamInviteMail;
use App\Models\AdminRole;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminTeamTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Http::preventStrayRequests();
        Http::fake(['ipapi.co/*' => Http::response(['error' => true], 429)]);
        Mail::fake();
        $this->owner = User::where('email', 'admin@musicdesk.test')->firstOrFail();
    }

    private function role(string $name): AdminRole
    {
        return AdminRole::where('name', $name)->firstOrFail();
    }

    private function member(string $role, string $email = 'agent@musicdesk.test'): User
    {
        $u = User::factory()->create(['email' => $email, 'organisation_id' => null]);
        $u->forceFill(['is_admin' => true, 'admin_role_id' => $this->role($role)->id, 'invite_accepted_at' => now()])->save();

        return $u->fresh();
    }

    /** Invite through the page and return the link from the email. */
    private function invite(User $by, string $email, string $role): string
    {
        $this->actingAs($by)->post(route('admin.team.invite'), ['name' => 'Sam Lee', 'email' => $email, 'admin_role_id' => $this->role($role)->id])
            ->assertRedirect()->assertSessionHas('invite_link');
        $link = null;
        Mail::assertSent(TeamInviteMail::class, function ($m) use ($email, &$link) {
            $link = $m->link;

            return $m->hasTo($email);
        });

        return $link;
    }

    public function test_seeded_admin_is_owner_and_default_roles_exist(): void
    {
        $this->assertTrue($this->owner->isOwner());
        $this->assertSame(['Owner', 'Administrator', 'License manager', 'Support agent', 'Billing'], AdminRole::orderBy('sort')->pluck('name')->all());
        $this->actingAs($this->owner)->get(route('admin.team'))->assertOk()->assertSee('Team & roles')->assertSee('What each role can do');
    }

    public function test_roles_limit_admin_areas_and_the_menu(): void
    {
        $agent = $this->member('Support agent');

        $this->actingAs($agent)->get(route('admin.dashboard'))->assertOk()
            ->assertSee(route('admin.support'))->assertDontSee(route('admin.gateways'))->assertDontSee(route('admin.team'));
        $this->actingAs($agent)->get(route('admin.support'))->assertOk();
        $this->actingAs($agent)->get(route('admin.licenses'))->assertOk();
        $this->actingAs($agent)->get(route('admin.orders'))->assertForbidden();
        $this->actingAs($agent)->get(route('admin.gateways'))->assertForbidden();
        $this->actingAs($agent)->get(route('admin.settings', 'branding'))->assertForbidden();
        $this->actingAs($agent)->get(route('admin.license-status'))->assertForbidden();
        $this->actingAs($agent)->get(route('admin.team'))->assertForbidden();

        $billing = $this->member('Billing', 'billing@musicdesk.test');
        $this->actingAs($billing)->get(route('admin.settings', 'invoice'))->assertOk();
        $this->actingAs($billing)->get(route('admin.settings', 'branding'))->assertForbidden();
    }

    public function test_invite_accept_and_sign_in(): void
    {
        $link = $this->invite($this->owner, 'sam@musicdesk.test', 'Support agent');
        $sam = User::where('email', 'sam@musicdesk.test')->firstOrFail();
        $this->assertTrue($sam->invitePending());
        $this->assertSame($this->owner->id, $sam->invited_by);

        auth()->logout();
        $this->get($link)->assertOk()->assertSee('Join the admin team')->assertSee('Support agent');
        $this->post($link, ['name' => 'Sam Lee', 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->post($link, ['name' => 'Sam Lee', 'password' => 'correct-horse-42-battery', 'password_confirmation' => 'correct-horse-42-battery'])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($sam);
        $this->assertFalse($sam->fresh()->invitePending());

        // The link works once.
        auth()->logout();
        $this->get($link)->assertRedirect(route('login'));
        // Tampered links are refused.
        $this->get($link.'x')->assertForbidden();
    }

    public function test_resend_invalidates_the_old_link(): void
    {
        $old = $this->invite($this->owner, 'sam@musicdesk.test', 'Billing');
        $sam = User::where('email', 'sam@musicdesk.test')->firstOrFail();
        $this->travel(2)->seconds();
        $this->actingAs($this->owner)->post(route('admin.team.members.resend', $sam))->assertRedirect();
        auth()->logout();
        $this->get($old)->assertRedirect(route('login'));
    }

    public function test_invite_refuses_existing_team_members_and_customer_emails(): void
    {
        $this->actingAs($this->owner)->post(route('admin.team.invite'), ['name' => 'X', 'email' => 'admin@musicdesk.test', 'admin_role_id' => $this->role('Billing')->id])
            ->assertSessionHasErrorsIn('invite', 'email');
        $this->actingAs($this->owner)->post(route('admin.team.invite'), ['name' => 'X', 'email' => 'demo@lowtide.test', 'admin_role_id' => $this->role('Billing')->id])
            ->assertSessionHasErrorsIn('invite', 'email');
        $this->assertFalse(User::where('email', 'demo@lowtide.test')->value('is_admin'));
    }

    public function test_guard_rails(): void
    {
        // Self-changes and removing the last Owner are refused.
        $this->actingAs($this->owner)->put(route('admin.team.members.update', $this->owner), ['admin_role_id' => $this->role('Billing')->id])->assertForbidden();
        $this->actingAs($this->owner)->delete(route('admin.team.members.remove', $this->owner))->assertForbidden();

        // A team manager without full access can't hand out more than they have, or touch Owners.
        $manager = AdminRole::create(['name' => 'Team lead', 'permissions' => ['team', 'support', 'customers', 'licenses']]);
        $lead = $this->member('Team lead', 'lead@musicdesk.test');
        $this->actingAs($lead)->post(route('admin.team.invite'), ['name' => 'Y', 'email' => 'y@musicdesk.test', 'admin_role_id' => $this->role('Administrator')->id])->assertForbidden();
        $this->actingAs($lead)->post(route('admin.team.invite'), ['name' => 'Y', 'email' => 'y@musicdesk.test', 'admin_role_id' => $this->role('Owner')->id])->assertForbidden();
        $this->actingAs($lead)->post(route('admin.team.invite'), ['name' => 'Y', 'email' => 'y@musicdesk.test', 'admin_role_id' => $this->role('Support agent')->id])->assertRedirect();
        $this->actingAs($lead)->delete(route('admin.team.members.remove', $this->owner))->assertForbidden();
        $this->actingAs($lead)->post(route('admin.team.roles.store'), ['name' => 'Everything', 'permissions' => array_keys(AdminRole::PERMISSIONS)])->assertForbidden();
        $this->actingAs($lead)->put(route('admin.team.roles.update', $manager), ['name' => 'Team lead', 'permissions' => array_keys(AdminRole::PERMISSIONS)])->assertForbidden();

        // With a second Owner, the first can be demoted.
        $second = $this->member('Owner', 'owner2@musicdesk.test');
        $this->actingAs($second)->put(route('admin.team.members.update', $this->owner), ['admin_role_id' => $this->role('Administrator')->id])->assertRedirect();
        $this->assertFalse($this->owner->fresh()->isOwner());
    }

    public function test_remove_member_keeps_customer_account_but_drops_access(): void
    {
        $agent = $this->member('Support agent');
        $this->actingAs($this->owner)->delete(route('admin.team.members.remove', $agent))->assertRedirect();
        $this->assertNull(User::find($agent->id));

        $both = User::where('email', 'demo@lowtide.test')->firstOrFail();
        $both->forceFill(['is_admin' => true, 'admin_role_id' => $this->role('Billing')->id, 'invite_accepted_at' => now()])->save();
        $this->actingAs($this->owner)->delete(route('admin.team.members.remove', $both))->assertRedirect();
        $both->refresh();
        $this->assertFalse($both->is_admin);
        $this->assertNotNull($both->organisation_id);
        $this->actingAs($both)->get(route('admin.dashboard'))->assertForbidden();
    }

    public function test_owner_manages_roles(): void
    {
        $this->actingAs($this->owner)->post(route('admin.team.roles.store'), ['name' => 'Release manager', 'description' => 'Ships versions', 'permissions' => ['releases', 'nonsense']])
            ->assertSessionHasErrors('permissions.1');
        $this->actingAs($this->owner)->post(route('admin.team.roles.store'), ['name' => 'Release manager', 'permissions' => ['releases']])->assertRedirect();
        $role = $this->role('Release manager');
        $this->assertSame(['releases'], $role->permissions);

        $this->actingAs($this->owner)->put(route('admin.team.roles.update', $role), ['name' => 'Release manager', 'permissions' => ['releases', 'support']])->assertRedirect();
        $this->assertTrue($role->fresh()->allows('support'));

        $this->actingAs($this->owner)->put(route('admin.team.roles.update', $this->role('Owner')), ['name' => 'Owner', 'permissions' => ['support']])->assertForbidden();
        $this->member('Release manager');
        $this->actingAs($this->owner)->delete(route('admin.team.roles.destroy', $role))->assertRedirect();
        $this->assertNotNull($role->fresh(), 'roles with members are kept');
    }

    public function test_notifications_follow_permissions(): void
    {
        $this->member('Support agent', 'agent@musicdesk.test');
        $this->member('Billing', 'billing@musicdesk.test');
        $this->assertEqualsCanonicalizing(['admin@musicdesk.test', 'agent@musicdesk.test'], User::staffEmails('support'));
        $this->assertNotContains('billing@musicdesk.test', User::staffEmails('security'));

        $customer = User::where('email', 'demo@lowtide.test')->firstOrFail();
        $this->actingAs($customer)->post(route('dashboard.support.store'), ['category' => 'other', 'priority' => 'normal', 'subject' => 'Hi', 'body' => 'Question']);
        Mail::assertSent(SupportTicketMail::class, fn ($m) => $m->hasTo('admin@musicdesk.test') && $m->hasCc('agent@musicdesk.test') && ! $m->hasCc('billing@musicdesk.test'));
    }
}
