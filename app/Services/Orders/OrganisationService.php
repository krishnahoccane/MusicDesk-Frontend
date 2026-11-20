<?php

namespace App\Services\Orders;

use App\Models\Organisation;
use App\Models\User;
use App\Services\Billing\BillingClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrganisationService
{
    public function __construct(private BillingClient $billing) {}

    /** New customer: user + organisation here, then the organisation in billing.twsd.me. */
    public function register(array $data): User
    {
        $currency = app()->bound('locale.currency') ? app('locale.currency') : 'USD';
        $timezone = user_timezone();

        $user = DB::transaction(function () use ($data, $currency, $timezone) {
            $org = Organisation::create([
                'name' => $data['company'],
                'email' => $data['email'],
                'country' => isset($data['country']) ? strtoupper($data['country']) : (app()->bound('locale.country') ? app('locale.country') : null),
                'vat_id' => $data['vat_id'] ?? null,
                'currency' => $currency,
                'timezone' => $timezone,
            ]);

            return User::create([
                'organisation_id' => $org->id,
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => 'owner',
                'timezone' => $timezone !== 'UTC' ? $timezone : null,
                'currency' => $currency,
                'country' => $org->country,
            ]);
        });

        $this->syncToBilling($user->organisation);

        return $user;
    }

    /**
     * Create or update the organisation in billing. Billing currently creates customers with the
     * first paid order, so for a new organisation this usually defers until checkout.
     * Failures are logged; signup and checkout never block on billing.
     */
    public function syncToBilling(Organisation $org): void
    {
        try {
            if ($org->billing_org_id) {
                $this->billing->updateOrganisation($org);
            } elseif ($id = $this->billing->createOrganisation($org)) {
                $org->billing_org_id = $id;
            } else {
                return;   // deferred to the first order
            }
            $org->billing_synced_at = now();
            $org->save();
        } catch (\Throwable $e) {
            Log::error('Billing organisation sync failed', ['org' => $org->id, 'error' => $e->getMessage()]);
        }
    }
}
