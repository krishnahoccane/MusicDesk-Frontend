<?php

namespace Tests\Feature;

use App\Mail\TrialNoticeMail;
use App\Models\AdminRole;
use App\Models\Invoice;
use App\Models\License;
use App\Models\LicenseActivation;
use App\Models\Order;
use App\Models\Organisation;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** MusicDesk.tech 2.0.1: free trials on pricing plans, and deleting orders / licenses / customers. */
class FreeTrialAndDeleteTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Http::preventStrayRequests();
        Http::fake(['ipapi.co/*' => Http::response(['error' => true], 429)]);
        Mail::fake();
        $this->admin = User::where('email', 'admin@musicdesk.test')->firstOrFail();
    }

    private function claims(string $token): array
    {
        return json_decode(base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);
    }

    /** A guest starts the Starter trial; returns the new trial license. */
    private function startTrial(string $email = 'trial@newlabel.test'): License
    {
        $this->post(route('trial.store', 'starter'), [
            'name' => 'Tia Trial', 'email' => $email, 'password' => 'a-long-password-1', 'company' => 'New Label Ltd', 'country' => 'GB', 'terms' => '1',
        ])->assertRedirect(route('dashboard.licenses'));

        return License::where('billing_type', License::TRIAL)->latest('id')->firstOrFail();
    }

    // ------------------------------------------------------------ admin: trial settings

    public function test_admin_sets_a_trial_with_days_and_limits(): void
    {
        $plan = Plan::where('code', 'business')->firstOrFail();
        $base = ['code' => 'business', 'name' => 'Business', 'price_annual' => 1299, 'domains' => 3, 'extra_domain_price' => 129, 'active' => '1'];

        $this->actingAs($this->admin)->put(route('admin.plans.update', $plan), $base + ['trial_enabled' => '1'])->assertSessionHasErrors('trial_days');
        $this->actingAs($this->admin)->put(route('admin.plans.update', $plan), $base + [
            'trial_enabled' => '1', 'trial_days' => 30, 'trial_domains' => 2, 'trial_users' => 5, 'trial_releases' => 100, 'trial_addons' => '1', 'trial_note' => 'Watermarked statements',
        ])->assertSessionHasNoErrors();

        $plan->refresh();
        $this->assertTrue($plan->offersTrial());
        $this->assertSame(30, $plan->trial_days);
        $this->assertSame(['domains' => 2, 'users' => 5, 'artists' => null, 'releases' => 100, 'tracks' => null, 'addons' => true, 'note' => 'Watermarked statements'], $plan->trialLimits());
        $this->actingAs($this->admin)->get(route('admin.plans'))->assertOk()->assertSee('30-day free trial')->assertSee('Offer a free trial of this plan');
        $this->get(route('pricing'))->assertOk()->assertSee('Start 30-day free trial')->assertSee('2 production domains · 5 users · 100 releases · add-ons included');

        $this->actingAs($this->admin)->put(route('admin.plans.update', $plan), $base)->assertSessionHasNoErrors();
        $this->assertFalse($plan->fresh()->offersTrial());
    }

    // ------------------------------------------------------------ customer: starting and using a trial

    public function test_guest_starts_a_trial_once_and_the_token_carries_the_limits(): void
    {
        $this->get(route('trial', 'starter'))->assertOk()->assertSee('Try LabelDesk Starter free for 14 days')->assertSee('Up to 25 artists')->assertSee('No store deliveries during the trial');
        $this->get(route('trial', 'enterprise'))->assertRedirect(route('checkout', 'enterprise'));

        $trial = $this->startTrial();
        $this->assertAuthenticated();
        $this->assertSame('active', $trial->displayStatus());
        $this->assertSame(1, $trial->domainLimit());
        $this->assertTrue($trial->expires_at->between(now()->addDays(14)->subMinute(), now()->addDays(14)->addMinute()));
        $this->assertNotNull($trial->organisation->trial_used_at);
        $this->assertFalse($trial->addonLicenses()->exists(), 'no add-ons in this trial');

        // Portal: banner, limits and buy link instead of renewals.
        $this->get(route('dashboard.licenses'))->assertOk()->assertSee('Free trial:')->assertSee('14 days left')
            ->assertSee('1 production domain · 3 users · 25 artists · 50 releases · no add-ons')->assertSee('Buy Starter (keeps this key)')->assertDontSee('Switch to lifetime');
        $this->get(route('licenses.buy', [$trial, 'renewal']))->assertRedirect(route('dashboard.licenses'));

        // One trial per account.
        $this->post(route('trial.store', 'starter'), ['company' => 'New Label Ltd', 'country' => 'GB', 'terms' => '1'])->assertSessionHasErrors('trial');
        $this->assertSame(1, License::where('billing_type', 'trial')->count());

        // The token and response tell LabelDesk about the trial.
        $res = $this->postJson('/api/license/v1/activate', ['key' => $trial->key(), 'domain' => 'portal.newlabel.com'])->assertOk()
            ->assertJsonPath('trial', true)->assertJsonPath('limits.artists', 25)->assertJsonPath('limits.tracks', null)->assertJsonPath('limits.domains', 1);
        $claims = $this->claims($res->json('token'));
        $this->assertTrue($claims['trial']);
        $this->assertSame(['domains' => 1, 'users' => 3, 'artists' => 25, 'releases' => 50, 'tracks' => null, 'addons' => false], $claims['limits']);
        $this->assertSame($trial->expires_at->timestamp, $claims['expires']);
        $this->assertStringContainsString('Free trial: 14 days left', $res->json('messages.0'));
        $this->postJson('/api/license/v1/activate', ['key' => $trial->key(), 'domain' => 'portal2.newlabel.com'])->assertStatus(409)->assertJsonPath('error.code', 'domain_limit_reached');
    }

    public function test_a_domain_runs_only_one_trial(): void
    {
        $first = $this->startTrial('one@newlabel.test');
        $this->postJson('/api/license/v1/activate', ['key' => $first->key(), 'domain' => 'portal.newlabel.com'])->assertOk();
        $first->activations()->update(['status' => LicenseActivation::DEACTIVATED]);

        auth()->logout();
        $second = $this->startTrial('two@newlabel.test');
        $this->postJson('/api/license/v1/activate', ['key' => $second->key(), 'domain' => 'portal.newlabel.com'])
            ->assertStatus(409)->assertJsonPath('error.code', 'trial_used');
        $this->postJson('/api/license/v1/activate', ['key' => $second->key(), 'domain' => 'other.newlabel.com'])->assertOk();
    }

    public function test_trial_ends_like_an_expired_license_then_is_suspended(): void
    {
        $trial = $this->startTrial();
        $token = $this->postJson('/api/license/v1/activate', ['key' => $trial->key(), 'domain' => 'portal.newlabel.com'])->json('token');

        $this->travel(12)->days();
        $this->artisan('licenses:end-trials')->assertSuccessful();
        Mail::assertSent(TrialNoticeMail::class, fn ($m) => $m->stage === 'ending' && $m->hasTo('trial@newlabel.test'));
        $this->assertSame('expiring', $trial->fresh()->displayStatus());

        // Ended: read-only for 10 days (status expired, still usable), "ended" email once.
        $this->travel(3)->days();
        $this->artisan('licenses:end-trials')->assertSuccessful();
        $this->artisan('licenses:end-trials')->assertSuccessful();
        $this->assertSame(1, Mail::sent(TrialNoticeMail::class, fn ($m) => $m->stage === 'ended')->count());
        $this->assertSame('expired', $trial->fresh()->displayStatus());
        $this->postJson('/api/license/v1/check-in', ['token' => $token])->assertOk()->assertJsonPath('status', 'expired')
            ->assertJsonPath('messages.0', 'Your free trial has ended. LabelDesk is read-only and will be locked after '.$trial->expires_at->copy()->addDays(10)->toFormattedDateString().' unless you buy a license.');

        // After the read-only period: suspended, check-ins refused.
        $this->travel(10)->days();
        $this->artisan('licenses:end-trials')->assertSuccessful();
        $trial->refresh();
        $this->assertSame([License::SUSPENDED, 'Free trial ended'], [$trial->status, $trial->status_reason]);
        Mail::assertSent(TrialNoticeMail::class, fn ($m) => $m->stage === 'suspended');
        $this->postJson('/api/license/v1/check-in', ['token' => $token])->assertForbidden()->assertJsonPath('error.code', 'license_suspended');
        $this->assertTrue($trial->events()->where('event', 'trial_ended')->exists());
    }

    public function test_buying_the_plan_converts_the_trial_with_the_same_key_even_after_suspension(): void
    {
        $trial = $this->startTrial();
        $key = $trial->key();
        $token = $this->postJson('/api/license/v1/activate', ['key' => $key, 'domain' => 'portal.newlabel.com'])->json('token');
        $this->travel(30)->days();
        $this->artisan('licenses:end-trials');
        $this->assertSame(License::SUSPENDED, $trial->fresh()->status);

        $this->post(route('checkout.store', 'business'), ['billing' => 'annual', 'company' => 'New Label Ltd', 'country' => 'GB', 'gateway' => 'bank_transfer', 'terms' => '1'])->assertRedirect();
        $order = Order::latest('id')->firstOrFail();
        $this->actingAs($this->admin)->post(route('admin.orders.paid', $order))->assertRedirect();

        $license = $trial->fresh();
        $this->assertSame($license->id, $order->fresh()->license_id);
        $this->assertSame(['business', 'annual', License::ACTIVE, null, null], [$license->plan->code, $license->billing_type, $license->status, $license->status_reason, $license->trial_limits]);
        $this->assertSame($key, $license->key());
        $this->assertSame(3, $license->domainLimit());
        $this->assertTrue($license->expires_at->isAfter(now()->addMonths(11)));
        $this->assertNotNull($license->trial_started_at, 'remembered: the domain still counts as having run a trial');
        $this->assertSame(1, License::core()->where('organisation_id', $license->organisation_id)->count(), 'no second license');
        $this->assertTrue($license->addonLicenses()->exists(), 'Business add-ons issued once paid');

        $this->postJson('/api/license/v1/check-in', ['token' => $token])->assertOk()->assertJsonPath('trial', false)->assertJsonPath('limits', null)->assertJsonPath('status', 'active');
    }

    // ------------------------------------------------------------ deleting

    public function test_delete_needs_the_permission_and_the_typed_number(): void
    {
        $license = License::core()->whereHas('plan', fn ($q) => $q->where('code', 'starter'))->where('billing_type', 'annual')->firstOrFail();
        $role = AdminRole::where('name', 'Administrator')->firstOrFail();
        $manager = User::factory()->create(['is_admin' => true, 'admin_role_id' => $role->id, 'organisation_id' => null]);

        $this->actingAs($manager)->get(route('admin.licenses.show', $license))->assertOk()->assertDontSee('Delete license');
        $this->actingAs($manager)->delete(route('admin.licenses.destroy', $license), ['confirm' => $license->number])->assertForbidden();
        $role->update(['permissions' => [...$role->permissions, 'delete']]);
        $manager = $manager->fresh();
        $this->actingAs($manager)->get(route('admin.licenses.show', $license))->assertOk()->assertSee('Delete license '.$license->number);

        $this->actingAs($manager)->delete(route('admin.licenses.destroy', $license), ['confirm' => 'LIC-WRONG'])->assertSessionHasErrors('confirm');
        $this->assertTrue(License::whereKey($license->id)->exists());
    }

    public function test_deleting_a_license_removes_its_addons_domains_and_history_but_keeps_orders(): void
    {
        $license = License::core()->whereHas('plan', fn ($q) => $q->where('code', 'business'))->where('billing_type', 'annual')->firstOrFail();
        $token = $this->postJson('/api/license/v1/activate', ['key' => $license->key(), 'domain' => 'dev.lowtide.com', 'environment' => 'staging'])->json('token');
        $addonIds = $license->addonLicenses()->pluck('id');
        $orders = $license->orders()->pluck('id');
        $this->assertNotEmpty($addonIds);

        $this->actingAs($this->admin)->delete(route('admin.licenses.destroy', $license), ['confirm' => $license->number])->assertRedirect(route('admin.licenses'));

        $this->assertFalse(License::whereKey($license->id)->exists());
        $this->assertSame(0, License::whereIn('id', $addonIds)->count());
        $this->assertSame(0, LicenseActivation::where('license_id', $license->id)->count());
        $this->assertSame($orders->count(), Order::whereIn('id', $orders)->whereNull('license_id')->count());
        $this->postJson('/api/license/v1/check-in', ['token' => $token])->assertNotFound()->assertJsonPath('error.code', 'activation_not_found');
    }

    /** Two paid orders (with invoices) from the demo customer. */
    private function paidOrders(): array
    {
        $customer = User::where('email', 'demo@lowtide.test')->firstOrFail();
        $license = License::core()->whereHas('plan', fn ($q) => $q->where('code', 'starter'))->where('billing_type', 'annual')->firstOrFail();
        $orders = [];
        foreach ([1, 2] as $n) {
            $this->actingAs($customer)->post(route('licenses.buy.store', [$license, 'extra_domains']), ['gateway' => 'bank_transfer', 'terms' => '1', 'extra_domains' => $n])->assertRedirect();
            $order = Order::latest('id')->firstOrFail();
            $this->actingAs($this->admin)->post(route('admin.orders.paid', $order))->assertRedirect();
            $orders[] = $order->fresh();
        }

        return $orders;
    }

    public function test_deleting_an_order_keeps_or_deletes_its_invoices(): void
    {
        [$keep, $drop] = $this->paidOrders();
        $this->assertNotNull($keep->invoice);
        $this->assertNotNull($drop->invoice);

        $this->actingAs($this->admin)->get(route('admin.orders'))->assertOk()->assertSee('Delete order')->assertSee('Also delete its invoices');
        $this->actingAs($this->admin)->delete(route('admin.orders.destroy', $keep), ['confirm' => $keep->number])->assertRedirect(route('admin.orders'));
        $this->assertFalse(Order::whereKey($keep->id)->exists());
        $this->assertTrue(Invoice::where('organisation_id', $keep->organisation_id)->whereNull('order_id')->exists(), 'invoice kept, unlinked');

        $invoice = $drop->invoice;
        $this->actingAs($this->admin)->delete(route('admin.orders.destroy', $drop), ['confirm' => $drop->number, 'with_invoices' => '1'])->assertRedirect();
        $this->assertFalse(Invoice::whereKey($invoice->id)->exists());
        if ($drop->license_id) {
            $this->assertTrue(License::whereKey($drop->license_id)->exists(), 'license kept');
        }
    }

    public function test_deleting_a_customer_removes_everything_they_own(): void
    {
        $this->paidOrders();
        $org = Organisation::where('email', \App\Support\Demo::DEMO_ORG_EMAIL)->firstOrFail();
        $userIds = $org->users()->pluck('id');
        $this->assertTrue($org->licenses()->exists() && $org->orders()->exists() && $userIds->isNotEmpty());

        $this->actingAs($this->admin)->get(route('admin.customers.show', $org))->assertOk()->assertSee('Delete customer');
        $this->actingAs($this->admin)->delete(route('admin.customers.destroy', $org), ['confirm' => 'Lowtide'])->assertSessionHasErrors('confirm');
        $this->actingAs($this->admin)->delete(route('admin.customers.destroy', $org), ['confirm' => $org->name])->assertRedirect(route('admin.customers'));

        $this->assertFalse(Organisation::whereKey($org->id)->exists());
        $this->assertSame(0, License::where('organisation_id', $org->id)->count());
        $this->assertSame(0, Order::where('organisation_id', $org->id)->count());
        $this->assertSame(0, Invoice::where('organisation_id', $org->id)->count());
        $this->assertSame(0, User::whereIn('id', $userIds)->count());
        $this->assertTrue(User::whereKey($this->admin->id)->exists());
    }
}
