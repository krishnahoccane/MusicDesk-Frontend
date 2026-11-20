<?php

namespace App\Licensing;

use App\Models\License;
use App\Models\LicenseEvent;
use App\Models\Organisation;
use App\Models\Plan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every change to a license goes through here (created, renewed, upgraded, domains added,
 * suspended / reinstated / revoked, key revealed or regenerated) and is written to the
 * license history with who did it.
 */
class LicenseService
{
    /**
     * @param  array{billing_type: string, domains_extra?: int, price_paid?: float, currency?: string,
     *               pending?: bool, auto_renew?: ?bool, purchased_at?: Carbon, notes?: ?string}  $options
     * @return array{0: License, 1: string} the license and its plain key (show it once)
     */
    public function create(Organisation $org, Plan $plan, array $options, string $actor): array
    {
        if (! in_array($options['billing_type'], ['annual', 'lifetime'], true)) {
            throw new LicenseException('invalid_billing_type', 'Billing type must be annual or lifetime.', 422, 'billing_type');
        }

        $purchased = $options['purchased_at'] ?? now();
        $annual = $options['billing_type'] === 'annual';
        $key = LicenseKey::generate();

        $license = DB::transaction(function () use ($org, $plan, $options, $purchased, $annual, $key, $actor) {
            $license = License::create([
                'number' => $this->nextNumber(),
                'organisation_id' => $org->id,
                'plan_id' => $plan->id,
                'billing_type' => $options['billing_type'],
                'status' => ($options['pending'] ?? false) ? License::PENDING : License::ACTIVE,
                'pending_until' => ($options['pending'] ?? false) ? $purchased->copy()->addDays(License::PENDING_DAYS) : null,
                'key_hash' => LicenseKey::hash($key),
                'key_encrypted' => $key,
                'key_last4' => substr($key, -4),
                'domains_included' => $plan->domains,
                'domains_extra' => max(0, (int) ($options['domains_extra'] ?? 0)),
                'purchased_at' => $purchased,
                // Annual: one year. Lifetime: no expiry, 12 months of updates and support.
                'expires_at' => $annual ? $purchased->copy()->addYear() : null,
                'support_until' => $purchased->copy()->addYear(),
                'auto_renew' => $options['auto_renew'] ?? $annual,
                'price_paid' => (float) ($options['price_paid'] ?? 0),
                'currency' => strtoupper($options['currency'] ?? 'USD'),
                'notes' => $options['notes'] ?? null,
            ]);
            $this->event($license, 'created', ['plan' => $plan->code, 'billing_type' => $license->billing_type, 'domains' => $license->domainLimit()], $actor);

            return $license;
        });

        return [$license, $key];
    }

    /** Annual: one more year from the later of now and the current expiry (updates included). */
    public function renew(License $license, string $actor): License
    {
        $this->requireUsable($license);

        return $this->change($license, 'renewed', function (License $l) {
            $from = $l->expires_at && $l->expires_at->isFuture() ? $l->expires_at->copy() : now();
            $l->expires_at = $from->addYear();
            $l->support_until = $l->expires_at;
            $l->max_version = null;
            $this->activatePending($l);
        }, $actor);
    }

    /** Lifetime: one more year of updates and support. */
    public function renewUpdates(License $license, string $actor): License
    {
        $this->requireUsable($license);

        return $this->change($license, 'updates_renewed', function (License $l) {
            $from = $l->support_until && $l->support_until->isFuture() ? $l->support_until->copy() : now();
            $l->support_until = $from->addYear();
            $l->max_version = null;
            $this->activatePending($l);
        }, $actor);
    }

    public function addDomains(License $license, int $count, string $actor): License
    {
        if ($count < 1 || $count > 100) {
            throw new LicenseException('invalid_quantity', 'Add between 1 and 100 domains.', 422, 'extra_domains');
        }
        $this->requireUsable($license);

        return $this->change($license, 'domains_added', fn (License $l) => $l->domains_extra += $count, $actor, ['added' => $count]);
    }

    /** Change tier: key, domains, dates and extra domains are kept. */
    public function changePlan(License $license, Plan $plan, string $actor): License
    {
        $this->requireUsable($license);
        $from = $license->plan?->code;

        return $this->change($license, 'plan_changed', function (License $l) use ($plan) {
            $l->plan_id = $plan->id;
            $l->domains_included = $plan->domains;
        }, $actor, ['from' => $from, 'to' => $plan->code]);
    }

    /** Annual → lifetime: no expiry, 12 months of updates from now. */
    public function convertToLifetime(License $license, string $actor): License
    {
        if ($license->billing_type === 'lifetime') {
            throw new LicenseException('already_lifetime', 'This license is already a lifetime license.');
        }
        $this->requireUsable($license);

        return $this->change($license, 'converted_to_lifetime', function (License $l) {
            $l->billing_type = 'lifetime';
            $l->expires_at = null;
            $l->auto_renew = false;
            $l->support_until = now()->addYear();
            $l->max_version = null;
        }, $actor);
    }

    public function setAutoRenew(License $license, bool $on, string $actor): License
    {
        if ($license->billing_type !== 'annual') {
            throw new LicenseException('not_annual', 'Only annual licenses renew.', 422);
        }

        return $this->change($license, $on ? 'auto_renew_on' : 'auto_renew_off', fn (License $l) => $l->auto_renew = $on, $actor);
    }

    /** Admin: suspend (installs go read-only at their next check-in), revoke (permanent), or reinstate. */
    public function setStatus(License $license, string $status, string $reason, string $actor): License
    {
        if (! in_array($status, [License::ACTIVE, License::SUSPENDED, License::REVOKED], true)) {
            throw new LicenseException('invalid_status', 'Unknown status.', 422, 'status');
        }
        if ($license->status === License::REVOKED) {
            throw new LicenseException('license_revoked', 'A revoked license cannot be changed.');
        }
        $event = match ($status) {
            License::SUSPENDED => 'suspended',
            License::REVOKED => 'revoked',
            default => 'reinstated',
        };

        return $this->change($license, $event, function (License $l) use ($status, $reason) {
            $l->status = $status;
            $l->status_reason = $status === License::ACTIVE ? null : $reason;
            if ($status === License::ACTIVE) {
                $l->pending_until = null;
            }
        }, $actor, ['reason' => $reason]);
    }

    /** Bank transfer arrived: a pending license (even past its grace period) becomes active. */
    public function confirmPayment(License $license, string $reference, string $actor): License
    {
        if ($license->status !== License::PENDING) {
            return $license;
        }

        return $this->change($license, 'payment_confirmed', fn (License $l) => $this->activatePending($l), $actor, ['reference' => $reference]);
    }

    public function revokeForRefund(License $license, string $reason, string $actor): License
    {
        if ($license->status === License::REVOKED) {
            return $license;
        }

        return $this->change($license, 'revoked', function (License $l) use ($reason) {
            $l->status = License::REVOKED;
            $l->status_reason = $reason;
        }, $actor, ['reason' => $reason, 'refund' => true]);
    }

    /** Admin edit: plan, extra domains, dates, auto-renew, version cap, notes. */
    public function update(License $license, array $data, string $actor): License
    {
        return $this->change($license, 'details_changed', function (License $l) use ($data) {
            foreach (['plan_id', 'domains_extra', 'expires_at', 'support_until', 'auto_renew', 'max_version', 'notes'] as $field) {
                if (array_key_exists($field, $data)) {
                    $l->{$field} = $data[$field];
                }
            }
            if (array_key_exists('plan_id', $data)) {
                $l->domains_included = Plan::findOrFail($data['plan_id'])->domains;
            }
            if ($l->billing_type === 'lifetime') {
                $l->expires_at = null;
            }
        }, $actor, array_intersect_key($data, array_flip(['plan_id', 'domains_extra', 'expires_at', 'support_until', 'auto_renew', 'max_version'])));
    }

    /** The full key, for the owner or an admin. Every reveal is recorded. */
    public function revealKey(License $license, string $actor): string
    {
        $this->event($license, 'key_revealed', null, $actor);

        return $license->key();
    }

    /** New key; the old one stops working at the next check-in. Returns the new plain key. */
    public function regenerateKey(License $license, string $actor): string
    {
        $key = LicenseKey::generate();
        $this->change($license, 'key_regenerated', function (License $l) use ($key) {
            $l->key_hash = LicenseKey::hash($key);
            $l->key_encrypted = $key;
            $l->key_last4 = substr($key, -4);
        }, $actor);

        return $key;
    }

    public function findByKey(string $key): ?License
    {
        return License::query()->where('key_hash', LicenseKey::hash($key))->first();
    }

    public function event(License $license, string $event, ?array $details, string $actor): void
    {
        LicenseEvent::create([
            'license_id' => $license->id,
            'event' => $event,
            'details' => $details,
            'actor' => Str::limit($actor, 115, ''),
        ]);
    }

    private function change(License $license, string $event, callable $mutate, string $actor, array $details = []): License
    {
        return DB::transaction(function () use ($license, $event, $mutate, $actor, $details) {
            $license = License::query()->whereKey($license->id)->lockForUpdate()->firstOrFail();
            $mutate($license);
            $license->save();
            $this->event($license, $event, $details ?: null, $actor);

            return $license;
        });
    }

    private function activatePending(License $l): void
    {
        if ($l->status === License::PENDING) {
            $l->status = License::ACTIVE;
            $l->pending_until = null;
        }
    }

    private function requireUsable(License $license): void
    {
        if (in_array($license->status, [License::SUSPENDED, License::REVOKED], true)) {
            throw new LicenseException('license_'.$license->status, 'This license is '.$license->status.'.');
        }
    }

    private function nextNumber(): string
    {
        do {
            $number = 'LIC-'.substr(str_shuffle(str_repeat('ABCDEFGHJKMNPQRSTUVWXYZ23456789', 3)), 0, 6);
        } while (License::query()->where('number', $number)->exists());

        return $number;
    }

    /** Who did it, for the history: "Ada (admin)", "customer: ada@x.com", or a system label. */
    public static function actor(?\App\Models\User $user = null, ?string $fallback = null): string
    {
        if ($user) {
            return $user->is_admin && ! $user->organisation_id ? $user->name.' (admin)' : 'customer: '.$user->email;
        }

        return $fallback ?? 'system';
    }
}
