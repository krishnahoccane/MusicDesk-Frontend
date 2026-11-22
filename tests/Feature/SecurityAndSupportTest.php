<?php

namespace Tests\Feature;

use App\Mail\SecurityAlertMail;
use App\Mail\SupportTicketMail;
use App\Models\License;
use App\Models\LicenseActivation;
use App\Models\LicenseAlert;
use App\Models\Organisation;
use App\Models\Plan;
use App\Models\SupportTicket;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SecurityAndSupportTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Http::preventStrayRequests();
        Http::fake(['ipapi.co/*' => Http::response(['error' => true], 429)]);
        Mail::fake();
        $this->customer = User::where('email', 'demo@lowtide.test')->firstOrFail();
        $this->admin = User::where('email', 'admin@musicdesk.test')->firstOrFail();
    }

    private function business(): License
    {
        return License::whereHas('plan', fn ($q) => $q->where('code', 'business'))->firstOrFail();
    }

    // ------------------------------------------------------------ security webhook

    public function test_failed_check_report_creates_one_alert_per_domain_and_emails_admins_once(): void
    {
        $payload = ['domain' => 'https://Pirate-Label.example/admin', 'reason' => 'signature_invalid', 'version' => '4.3.0', 'message' => 'Bad signature'];

        $this->postJson('/api/license/v1/report', $payload)->assertStatus(202)->assertJsonPath('received', true)->assertJsonPath('action', 'none');
        $this->postJson('/api/license/v1/report', [...$payload, 'reason' => 'no_license'])->assertStatus(202);

        $alert = LicenseAlert::where('domain', 'pirate-label.example')->firstOrFail();
        $this->assertSame(2, $alert->attempts);
        $this->assertSame(['signature_invalid' => 1, 'no_license' => 1], $alert->reasons);
        $this->assertSame('open', $alert->status);
        $this->assertNull($alert->license_id);

        Mail::assertSent(SecurityAlertMail::class, 1);
        Mail::assertSent(SecurityAlertMail::class, fn ($m) => $m->hasTo($this->admin->email) && $m->alert->domain === 'pirate-label.example');
    }

    public function test_report_matches_the_key_without_storing_it(): void
    {
        $license = $this->business();
        $this->postJson('/api/license/v1/report', ['domain' => 'copy.example.org', 'reason' => 'domain_mismatch', 'key' => $license->key()])->assertStatus(202);

        $alert = LicenseAlert::where('domain', 'copy.example.org')->firstOrFail();
        $this->assertSame($license->id, $alert->license_id);
        $this->assertStringNotContainsString($license->key(), json_encode($alert->toArray()));
    }

    public function test_report_validates_and_is_rate_limited(): void
    {
        $this->postJson('/api/license/v1/report', ['domain' => 'not a domain', 'reason' => 'no_license'])->assertStatus(422)->assertJsonPath('error.code', 'invalid_domain');
        $this->postJson('/api/license/v1/report', ['reason' => 'no_license'])->assertStatus(422)->assertJsonPath('error.field', 'domain');

        for ($i = 0; $i < 8; $i++) {
            $this->postJson('/api/license/v1/report', ['domain' => "flood{$i}.example", 'reason' => 'no_license']);
        }
        $this->postJson('/api/license/v1/report', ['domain' => 'flood-x.example', 'reason' => 'no_license'])->assertStatus(429);
    }

    public function test_license_server_refusals_raise_alerts_but_normal_limits_do_not(): void
    {
        $this->postJson('/api/license/v1/activate', ['key' => 'LDSK-AAAA-BBBB-CCCC-DDDD', 'domain' => 'guess.example.net'])->assertNotFound();
        $alert = LicenseAlert::where('domain', 'guess.example.net')->firstOrFail();
        $this->assertSame('invalid_key', $alert->last_reason);
        $this->assertSame('server', $alert->source);

        // Domain limit reached is a customer problem, not a security one.
        $full = License::where('billing_type', 'annual')->whereHas('plan', fn ($q) => $q->where('code', 'starter'))->firstOrFail();   // 1 of 1 used
        $this->postJson('/api/license/v1/activate', ['key' => $full->key(), 'domain' => 'fifth.example.com'])->assertStatus(409);
        $this->assertFalse(LicenseAlert::where('domain', 'fifth.example.com')->exists());

        // A forged check-in token is tied to the domain it claims.
        $forged = 'eyJhbGciOiJFZERTQSJ9.'.rtrim(strtr(base64_encode(json_encode(['dom' => 'forged.example.io', 'lic' => 'x'])), '+/', '-_'), '=').'.AAAA';
        $this->postJson('/api/license/v1/check-in', ['token' => $forged])->assertStatus(401);
        $this->assertSame('invalid_token', LicenseAlert::where('domain', 'forged.example.io')->value('last_reason'));
    }

    public function test_blocking_a_domain_stops_activation_check_in_and_tells_the_install_to_lock(): void
    {
        $license = $this->business();
        $res = $this->postJson('/api/license/v1/activate', ['key' => $license->key(), 'domain' => 'qa.lowtide.com', 'environment' => 'staging'])->assertOk();
        $token = $res->json('token');

        $this->postJson('/api/license/v1/report', ['domain' => 'qa.lowtide.com', 'reason' => 'files_tampered'])->assertStatus(202);
        $alert = LicenseAlert::where('domain', 'qa.lowtide.com')->firstOrFail();

        $this->actingAs($this->admin)->post(route('admin.licenses.alerts.block', $alert), ['note' => 'Tampered'])->assertRedirect();
        $this->assertSame('blocked', $alert->fresh()->status);
        $this->assertSame(LicenseActivation::DEACTIVATED, $license->activations()->where('domain', 'qa.lowtide.com')->value('status'), 'blocking deactivates it on the license');

        $this->postJson('/api/license/v1/activate', ['key' => $license->key(), 'domain' => 'qa.lowtide.com'])->assertForbidden()->assertJsonPath('error.code', 'domain_blocked');
        $this->postJson('/api/license/v1/check-in', ['token' => $token])->assertForbidden();
        $this->postJson('/api/license/v1/report', ['domain' => 'qa.lowtide.com', 'reason' => 'no_license'])->assertJsonPath('action', 'lock');
        $this->actingAs($this->customer)->postJson(route('dashboard.licenses.domains.add', $license), ['domain' => 'qa.lowtide.com', 'environment' => 'staging'])->assertStatus(403);

        // Unblock.
        $this->actingAs($this->admin)->put(route('admin.licenses.alerts.update', $alert), ['status' => 'dismissed'])->assertRedirect();
        $this->postJson('/api/license/v1/activate', ['key' => $license->key(), 'domain' => 'qa.lowtide.com', 'environment' => 'staging'])->assertOk();
    }

    public function test_block_a_domain_by_hand(): void
    {
        $this->actingAs($this->admin)->post(route('admin.licenses.alerts.block-domain'), ['domain' => 'www.Warez-Labels.example'])->assertRedirect();
        $this->assertTrue(LicenseAlert::isBlocked('warez-labels.example'));
    }

    public function test_issue_license_from_an_alert_closes_it(): void
    {
        $this->postJson('/api/license/v1/report', ['domain' => 'newlabel.example.com', 'reason' => 'no_license'])->assertStatus(202);
        $alert = LicenseAlert::where('domain', 'newlabel.example.com')->firstOrFail();

        $this->actingAs($this->admin)->get(route('admin.licenses.create', ['domain' => $alert->domain, 'alert' => $alert->id]))
            ->assertOk()->assertSee('value="newlabel.example.com"', false)->assertSee('name="alert_id"', false);

        $this->actingAs($this->admin)->post(route('admin.licenses.store'), [
            'new_org_name' => 'New Label', 'new_org_email' => 'ops@newlabel.example.com', 'new_org_country' => 'US',
            'plan_id' => Plan::where('code', 'starter')->value('id'), 'billing_type' => 'annual', 'status' => 'active',
            'primary_domain' => 'newlabel.example.com', 'alert_id' => $alert->id,
        ])->assertRedirect();

        $alert->refresh();
        $license = License::latest('id')->firstOrFail();
        $this->assertSame('issued', $alert->status);
        $this->assertSame($license->id, $alert->license_id);
        $this->assertTrue($license->activations()->where('domain', 'newlabel.example.com')->exists());
    }

    public function test_status_page_lists_domains_and_alerts(): void
    {
        $this->actingAs($this->admin)->get(route('admin.license-status'))->assertOk()
            ->assertSee('crackedlabel-demo.example')->assertSee('Issue license')->assertSee('music.lowtide.com')
            ->assertDontSee('nulled-scripts.example');
        $this->actingAs($this->admin)->get(route('admin.license-status', ['alerts' => 'blocked']))->assertOk()->assertSee('nulled-scripts.example')->assertSee('Unblock');
        $this->actingAs($this->customer)->get(route('admin.license-status'))->assertForbidden();
    }

    // ------------------------------------------------------------ support tickets

    public function test_customer_opens_a_ticket_and_admin_replies(): void
    {
        Storage::fake('local');
        $license = $this->business();

        $this->actingAs($this->customer)->post(route('dashboard.support.store'), [
            'license' => $license->uuid, 'domain' => 'music.lowtide.com', 'category' => 'bug', 'priority' => 'high',
            'subject' => 'Statements export times out', 'body' => 'Export of 50k rows times out after 30s.',
            'files' => [UploadedFile::fake()->create('laravel.log', 20, 'text/plain')],
        ])->assertRedirect();

        $ticket = SupportTicket::latest('id')->firstOrFail();
        $this->assertMatchesRegularExpression('/^TCK-\d+$/', $ticket->number);
        $this->assertSame($license->id, $ticket->license_id);
        $this->assertSame('open', $ticket->status);
        Mail::assertSent(SupportTicketMail::class, fn ($m) => $m->toStaff && $m->isNew && $m->hasTo($this->admin->email));

        // Admin sees it in the inbox and can download the attachment.
        $this->actingAs($this->admin)->get(route('admin.support'))->assertOk()->assertSee('Statements export times out');
        $message = $ticket->messages()->first();
        $this->actingAs($this->admin)->get(route('admin.support.attachment', [$ticket, $message->id, 0]))->assertOk();

        // Internal note: not emailed, not visible to the customer.
        $this->actingAs($this->admin)->post(route('admin.support.reply', $ticket), ['body' => 'Check the PHP max_execution_time', 'internal' => 1])->assertRedirect();
        $this->assertSame('open', $ticket->fresh()->status);
        $this->actingAs($this->customer)->get(route('dashboard.support', ['t' => $ticket->number]))->assertOk()->assertDontSee('max_execution_time');

        // Public reply: emailed, ticket waits on the customer.
        $this->actingAs($this->admin)->post(route('admin.support.reply', $ticket), ['body' => 'Raise max_execution_time to 120.', 'then' => 'awaiting'])->assertRedirect();
        $this->assertSame('awaiting', $ticket->fresh()->status);
        Mail::assertSent(SupportTicketMail::class, fn ($m) => ! $m->toStaff && $m->hasTo('demo@lowtide.test'));
        $this->actingAs($this->customer)->get(route('dashboard.support', ['t' => $ticket->number]))->assertOk()->assertSee('Raise max_execution_time to 120.')->assertSee('Awaiting you');

        // Customer replies → back to support; then resolves.
        $this->actingAs($this->customer)->post(route('dashboard.support.reply', $ticket), ['body' => 'That fixed it.'])->assertRedirect();
        $this->assertSame('open', $ticket->fresh()->status);
        $this->actingAs($this->customer)->post(route('dashboard.support.status', $ticket), ['status' => 'resolved'])->assertRedirect();
        $this->assertSame('resolved', $ticket->fresh()->status);
    }

    public function test_tickets_are_private_to_the_organisation(): void
    {
        $ticket = SupportTicket::firstOrFail();
        $stranger = User::factory()->create(['organisation_id' => Organisation::create(['name' => 'Other', 'email' => 'o@other.test', 'country' => 'US'])->id, 'role' => 'owner']);

        $this->actingAs($stranger)->post(route('dashboard.support.reply', $ticket), ['body' => 'hi'])->assertNotFound();
        $this->actingAs($stranger)->get(route('dashboard.support'))->assertOk()->assertDontSee($ticket->subject);
        $this->actingAs($this->customer)->get(route('admin.support.show', $ticket))->assertForbidden();

        // A license of another organisation can't be attached to a ticket.
        $this->actingAs($stranger)->post(route('dashboard.support.store'), ['license' => $this->business()->uuid, 'category' => 'other', 'priority' => 'normal', 'subject' => 'x', 'body' => 'y']);
        $this->assertNull(SupportTicket::latest('id')->first()->license_id);
    }

    public function test_admin_updates_ticket_fields(): void
    {
        $ticket = SupportTicket::where('status', 'open')->firstOrFail();
        $this->actingAs($this->admin)->get(route('admin.support.show', $ticket))->assertOk()->assertSee($ticket->subject)->assertSee('Internal note');
        $this->actingAs($this->admin)->put(route('admin.support.update', $ticket), ['status' => 'resolved', 'priority' => 'high', 'category' => 'bug', 'assigned_to' => $this->admin->id])->assertRedirect();
        $ticket->refresh();
        $this->assertSame(['resolved', 'high', 'bug'], [$ticket->status, $ticket->priority, $ticket->category]);
        $this->assertNotNull($ticket->resolved_at);
    }
}
