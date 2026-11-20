<?php

namespace App\Services\Orders;

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class OrganisationService
{
    /** New customer: their organisation and its owner account. */
    public function register(array $data): User
    {
        $currency = app()->bound('locale.currency') ? app('locale.currency') : 'USD';
        $timezone = user_timezone();

        return DB::transaction(function () use ($data, $currency, $timezone) {
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
    }
}
