<?php

namespace App\Licensing;

use App\Models\License;
use App\Models\LicenseActivation;
use App\Models\Organisation;
use App\Models\Release;

/** Licenses in the array shape the portal views use. Never includes the full key. */
class LicensePresenter
{
    /** @return array<int, array<string, mixed>> */
    public static function forOrganisation(?Organisation $org): array
    {
        if (! $org) {
            return [];
        }

        return $org->licenses()->with(['plan', 'activations' => fn ($q) => $q->where('status', '!=', LicenseActivation::DEACTIVATED)->orderBy('id')])
            ->orderByRaw("case status when 'revoked' then 1 else 0 end")->latest('purchased_at')->get()
            ->map(fn (License $l) => self::present($l))->all();
    }

    public static function present(License $l): array
    {
        $status = $l->displayStatus();
        $latest = Release::latestVersion();

        return [
            'id' => $l->number,
            'uid' => $l->uuid,
            'product' => setting('branding.product_name', 'LabelDesk'),
            'tier' => $l->plan?->name ?? 'License',
            'plan_code' => $l->plan?->code,
            'type' => ucfirst($l->billing_type),
            'key' => $l->maskedKey(),
            'key_masked' => true,
            'status' => $status,
            'status_reason' => $l->status_reason,
            'domains_used' => $l->activations->where('environment', 'production')->whereIn('status', [LicenseActivation::PENDING, LicenseActivation::ACTIVE])->count(),
            'domains' => $l->domainLimit(),
            'purchased' => local_time($l->purchased_at)->format('M d, Y'),
            'renews' => $l->expires_at ? local_time($l->expires_at)->format('M d, Y') : null,
            'days_left' => $l->daysLeft(),
            'pending_until' => $l->pending_until ? local_time($l->pending_until)->format('M d, Y') : null,
            'price' => $l->price_paid,
            'price_currency' => $l->currency,
            'auto_renew' => $l->auto_renew,
            'support_until' => $l->support_until ? local_time($l->support_until)->format('M d, Y') : null,
            'support_expired' => $l->supportExpired(),
            'max_version' => $l->maxVersion(),
            'latest_version' => $latest,
            'installations' => $l->activations->map(fn (LicenseActivation $a) => [
                'id' => $a->uuid,
                'domain' => $a->domain,
                'env' => $a->environment,
                'status' => $a->status,
                'ip' => $a->maskedIp() ?? '—',
                'version' => $a->version ?? 'not installed',
                'php' => $a->php_version ?? '—',
                'last' => $a->last_check_in_at ? local_time($a->last_check_in_at)->diffForHumans() : 'never',
                'online' => $a->isOnline(),
            ])->values()->all(),
        ];
    }
}
