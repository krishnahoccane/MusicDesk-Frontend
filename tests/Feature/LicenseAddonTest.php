<?php

namespace Tests\Feature;

use App\Models\License;
use App\Models\LicenseActivation;
use App\Models\LicenseAddon;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Release;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class LicenseAddonTest extends TestCase
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

    private function main(string $plan, string $type): License
    {
        return License::core()->whereHas('plan', fn ($q) => $q->where('code', $plan))->where('billing_type', $type)->firstOrFail();
    }

    private function addon(string $code): LicenseAddon
    {
        return LicenseAddon::where('code', $code)->firstOrFail();
    }

    private function claims(string $token): array
    {
        return json_decode(base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);
    }

    public function test_admin_creates_an_addon_with_included_plans(): void
    {
        $plans = Plan::whereIn('code', ['starter', 'business'])->pluck('id')->all();
        $this->actingAs($this->admin)->post(route('admin.plans.license-addons.store'), [
            'name' => 'Analytics Pro', 'code' => 'analytics', 'price_annual' => 240, 'price_lifetime' => 690, 'plans' => $plans, 'active' => '1',
        ])->assertRedirect();

        $addon = $this->addon('analytics');
        $this->assertSame(240.0, $addon->price_annual);
        $this->assertEqualsCanonicalizing($plans, $addon->plans->pluck('id')->all());
        $this->assertFalse(License::where('addon_id', $addon->id)->exists(), 'existing licenses get it at their next change unless asked');

        $this->actingAs($this->admin)->post(route('admin.plans.license-addons.store'), ['name' => 'Dup', 'code' => 'analytics', 'price_annual' => 1])->assertSessionHasErrors('code');
        $this->actingAs($this->admin)->get(route('admin.plans'))->assertOk()->assertSee('Licensed add-ons')->assertSee('Analytics Pro');
        $this->get(route('pricing'))->assertOk()->assertSee('Analytics Pro')->assertSee('Included with Starter and Business');

        // "Issue to existing licenses now" gives every live license on those plans its own key.
        $this->actingAs($this->admin)->put(route('admin.plans.license-addons.update', $addon), [
            'name' => 'Analytics Pro', 'code' => 'analytics', 'price_annual' => 240, 'plans' => $plans, 'active' => '1', 'issue_existing' => '1',
        ])->assertRedirect();
        $this->assertSame(3, License::where('addon_id', $addon->id)->where('included', true)->count());
    }

    public function test_included_addon_has_its_own_key_and_follows_the_main_license(): void
    {
        $main = $this->main('business', 'annual');
        $ddex = $main->addonLicenses()->where('addon_id', $this->addon('ddex')->id)->firstOrFail();

        $this->assertTrue($ddex->included);
        $this->assertStringStartsWith('LDAD-', $ddex->key());
        $this->assertNotSame($main->key(), $ddex->key());
        $this->assertTrue($ddex->expires_at->equalTo($main->expires_at));

        // Renewing the main license moves the included add-on too; suspending it stops the add-on.
        app(\App\Licensing\LicenseService::class)->renew($main, 'test');
        $this->assertTrue($ddex->fresh()->expires_at->equalTo($main->fresh()->expires_at));
        app(\App\Licensing\LicenseService::class)->setStatus($main->fresh(), 'suspended', 'Unpaid', 'test');
        $this->assertFalse($ddex->fresh()->allowsUse());
    }

    public function test_buying_an_addon_creates_a_separate_key_and_refund_revokes_it(): void
    {
        $main = $this->main('starter', 'annual');
        $this->actingAs($this->customer)->get(route('licenses.buy', [$main, 'addon', 'addon' => 'content_id', 'billing' => 'lifetime']))
            ->assertOk()->assertSee('Add an add-on')->assertSee('YouTube Content ID');
        $this->actingAs($this->customer)->post(route('licenses.buy.store', [$main, 'addon']), ['gateway' => 'bank_transfer', 'terms' => '1', 'addon' => 'content_id', 'billing' => 'lifetime'])->assertRedirect();

        $order = Order::latest('id')->firstOrFail();
        $this->assertSame(['addon', 'lifetime'], [$order->kind, $order->billing_type]);
        $this->assertFalse($main->addonLicenses()->exists(), 'nothing until paid');

        $this->actingAs($this->admin)->post(route('admin.orders.paid', $order))->assertRedirect();
        $addon = $main->addonLicenses()->firstOrFail();
        $this->assertSame('content_id', $addon->addon->code);
        $this->assertFalse($addon->included);
        $this->assertNull($addon->expires_at);
        $this->assertStringStartsWith('LDAD-', $addon->key());
        $this->assertSame($order->total, $addon->price_paid);

        // Shown separately in the portal, and the add-on can't be bought twice.
        $this->actingAs($this->customer)->get(route('dashboard.licenses'))->assertOk()->assertSee($addon->maskedKey())->assertSee('YouTube Content ID');
        $this->actingAs($this->customer)->post(route('licenses.buy.store', [$main, 'addon']), ['gateway' => 'bank_transfer', 'terms' => '1', 'addon' => 'content_id'])
            ->assertSessionHasErrors('purchase');

        $this->actingAs($this->admin)->post(route('admin.orders.refund', $order), ['reason' => 'Changed mind'])->assertRedirect();
        $this->assertSame('revoked', $addon->fresh()->status);
        $this->assertSame('active', $main->fresh()->status);
    }

    public function test_upgrading_to_a_plan_that_includes_an_addon_issues_its_key(): void
    {
        $main = $this->main('starter', 'annual');
        $this->assertFalse($main->addonLicenses()->exists());

        $this->actingAs($this->customer)->get(route('licenses.buy', [$main, 'change_plan']))->assertOk()->assertSee('Includes DDEX delivery')->assertSee('(separate key)');
        $this->actingAs($this->customer)->post(route('licenses.buy.store', [$main, 'change_plan']), ['gateway' => 'bank_transfer', 'terms' => '1', 'plan' => 'business']);
        $this->actingAs($this->admin)->post(route('admin.orders.paid', Order::latest('id')->first()));

        $ddex = $main->addonLicenses()->where('addon_id', $this->addon('ddex')->id)->firstOrFail();
        $this->assertTrue($ddex->included);
        $this->assertNotSame($main->fresh()->key(), $ddex->key());
        $this->assertTrue($main->fresh()->events()->where('event', 'addon_added')->exists());
    }

    public function test_bought_addon_becomes_included_after_upgrade(): void
    {
        $main = $this->main('starter', 'annual');
        [$bought] = app(\App\Licensing\LicenseService::class)->createAddon($main, $this->addon('ddex'), false, ['billing_type' => 'annual'], 'test');
        app(\App\Licensing\LicenseService::class)->changePlan($main, Plan::where('code', 'business')->first(), 'test');

        $this->assertSame(1, $main->addonLicenses()->where('addon_id', $this->addon('ddex')->id)->count(), 'no second key');
        $this->assertTrue($bought->fresh()->included);
        $this->assertSame($bought->key(), $bought->fresh()->key(), 'same key kept');
    }

    public function test_addon_key_activates_only_on_the_main_licenses_domains(): void
    {
        $main = $this->main('business', 'annual');
        $ddex = $main->addonLicenses()->firstOrFail();

        $this->postJson('/api/license/v1/activate', ['key' => $ddex->key(), 'domain' => 'elsewhere.example.com'])
            ->assertStatus(409)->assertJsonPath('error.code', 'addon_domain_not_licensed');

        $res = $this->postJson('/api/license/v1/activate', ['key' => $ddex->key(), 'domain' => 'music.lowtide.com', 'version' => '1.2.0'])
            ->assertOk()->assertJsonPath('addon', 'ddex');
        $claims = $this->claims($res->json('token'));
        $this->assertSame('ddex', $claims['addon']);
        $this->assertSame($main->uuid, $claims['parent']);
        $this->assertSame(1, $ddex->activations()->where('status', 'active')->count());

        // The main key still works on the same domain (different product).
        $this->postJson('/api/license/v1/activate', ['key' => $main->key(), 'domain' => 'music.lowtide.com'])->assertOk();

        // Deactivating the domain on the main license removes it from the add-on too.
        $mainActivation = $main->activations()->where('domain', 'music.lowtide.com')->firstOrFail();
        $this->actingAs($this->admin)->delete(route('admin.licenses.domains.deactivate', [$main, $mainActivation]));
        $this->assertSame(LicenseActivation::DEACTIVATED, $ddex->activations()->where('domain', 'music.lowtide.com')->value('status'));
        $this->postJson('/api/license/v1/check-in', ['token' => $res->json('token')])->assertForbidden()->assertJsonPath('error.code', 'activation_deactivated');
    }

    public function test_addon_stops_when_the_main_license_is_revoked(): void
    {
        $main = $this->main('starter', 'lifetime');
        $contentId = $main->addonLicenses()->firstOrFail();
        $res = $this->postJson('/api/license/v1/activate', ['key' => $contentId->key(), 'domain' => 'labels.sunsetwax.co'])->assertOk();

        $this->actingAs($this->admin)->post(route('admin.licenses.status', $main), ['status' => 'revoked', 'reason' => 'Chargeback', 'confirm' => $main->number]);
        $this->postJson('/api/license/v1/check-in', ['token' => $res->json('token')])->assertForbidden();
    }

    public function test_addon_releases_and_downloads(): void
    {
        $ddex = $this->addon('ddex');

        // Same version number as LabelDesk is fine for an add-on, not twice for the same add-on.
        $this->actingAs($this->admin)->post(route('admin.releases.store'), ['addon_id' => $ddex->id, 'version' => '4.3.0', 'channel' => 'stable', 'released_at' => now()->toDateString(), 'published' => '1'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('admin.releases.store'), ['addon_id' => $ddex->id, 'version' => '4.3.0', 'channel' => 'stable', 'released_at' => now()->toDateString()])
            ->assertSessionHasErrors('version');
        $this->actingAs($this->admin)->get(route('admin.releases', ['product' => $ddex->id]))->assertOk()->assertSee('DDEX delivery add-on');

        $release = Release::where('addon_id', $ddex->id)->where('version', '1.2.0')->firstOrFail();
        $main = $this->main('business', 'annual');
        $this->assertFalse($main->mayDownload($release), 'the main key can’t download add-on packages');
        $this->assertTrue($main->addonLicenses()->first()->mayDownload($release));
        $this->assertFalse($main->addonLicenses()->first()->mayDownload(Release::core()->where('version', '4.3.0')->first()));
        $this->assertSame('4.3.2', Release::latestVersion(), 'add-on releases don’t change the LabelDesk version');

        $this->actingAs($this->customer)->get(route('dashboard.downloads'))->assertOk()->assertSee('Add-on packages')->assertSee('DDEX delivery');
    }

    public function test_admin_issues_an_addon_by_hand(): void
    {
        $main = $this->main('starter', 'annual');
        $this->actingAs($this->admin)->get(route('admin.licenses.show', $main))->assertOk()->assertSee('Issue add-on');
        $this->actingAs($this->admin)->post(route('admin.licenses.addons.store', $main), ['addon_id' => $this->addon('sso')->id, 'billing_type' => 'annual'])
            ->assertRedirect()->assertSessionHas('new_key', fn ($k) => str_starts_with($k, 'LDAD-'));
        $this->assertSame('sso', $main->addonLicenses()->first()->addon->code);
    }
}
