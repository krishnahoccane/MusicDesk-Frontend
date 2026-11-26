<?php

namespace App\Licensing;

use App\Models\License;
use App\Models\LicenseAddon;
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
            $this->syncAddons($license, $actor);

            return $license;
        });

        return [$license, $key];
    }

    /**
     * An add-on for a main license: its own license with its own LDAD- key.
     * Included add-ons (free with the plan) follow the main license's dates and status;
     * bought ones have their own term.
     *
     * @param  array{billing_type?: string, price_paid?: float, currency?: string, notes?: ?string}  $options
     * @return array{0: License, 1: string}
     */
    public function createAddon(License $parent, LicenseAddon $addon, bool $included, array $options, string $actor): array
    {
        if ($parent->isAddon()) {
            throw new LicenseException('invalid_parent', 'Add-ons attach to a main license.', 422);
        }
        if ($parent->addonLicenses()->where('addon_id', $addon->id)->where('status', '!=', License::REVOKED)->exists()) {
            throw new LicenseException('addon_exists', "{$parent->number} already has the {$addon->name} add-on.", 409, 'addon');
        }
        $billing = $included ? $parent->billing_type : ($options['billing_type'] ?? 'annual');
        if (! in_array($billing, ['annual', 'lifetime'], true)) {
            throw new LicenseException('invalid_billing_type', 'Billing type must be annual or lifetime.', 422, 'billing_type');
        }
        $key = LicenseKey::generate('LDAD');
        $now = now();

        $license = DB::transaction(function () use ($parent, $addon, $included, $options, $billing, $key, $now, $actor) {
            $license = License::create([
                'number' => $this->nextNumber(),
                'organisation_id' => $parent->organisation_id,
                'plan_id' => $parent->plan_id,
                'addon_id' => $addon->id,
                'parent_id' => $parent->id,
                'included' => $included,
                'billing_type' => $billing,
                'status' => $included ? $parent->status : License::ACTIVE,
                'status_reason' => $included ? $parent->status_reason : null,
                'pending_until' => $included ? $parent->pending_until : null,
                'key_hash' => LicenseKey::hash($key),
                'key_encrypted' => $key,
                'key_last4' => substr($key, -4),
                'domains_included' => 0,
                'domains_extra' => 0,
                'purchased_at' => $now,
                'expires_at' => $included ? $parent->expires_at : ($billing === 'annual' ? $now->copy()->addYear() : null),
                'support_until' => $included ? $parent->support_until : $now->copy()->addYear(),
                'auto_renew' => ! $included && $billing === 'annual',
                'price_paid' => $included ? 0 : (float) ($options['price_paid'] ?? 0),
                'currency' => strtoupper($options['currency'] ?? $parent->currency ?? 'USD'),
                'notes' => $options['notes'] ?? null,
            ]);
            $this->event($license, 'created', ['addon' => $addon->code, 'parent' => $parent->number, 'billing_type' => $billing, 'included' => $included], $actor);
            $this->event($parent, 'addon_added', ['addon' => $addon->name, 'number' => $license->number, 'included' => $included], $actor);

            return $license;
        });

        return [$license, $key];
    }

    /**
     * Keep add-ons in step with the main license's plan:
     *  - add-ons the plan includes get a key (or an existing bought one becomes included, so it no longer needs renewing)
     *  - included add-ons the plan no longer includes are suspended
     *  - included add-ons mirror the main license's status and dates
     */
    public function syncAddons(License $parent, string $actor): void
    {
        if ($parent->isAddon()) {
            return;
        }
        $parent->loadMissing('plan.includedAddons');
        $included = $parent->plan?->includedAddons ?? collect();
        if ($parent->isTrial() && ! ($parent->trial_limits['addons'] ?? false)) {
            $included = collect();   // a trial without add-ons
        }
        $children = $parent->addonLicenses()->with('addon')->get();

        foreach ($included as $addon) {
            $child = $children->first(fn (License $c) => $c->addon_id === $addon->id && $c->status !== License::REVOKED);
            if (! $child) {
                $this->createAddon($parent, $addon, true, [], $actor);
            } elseif (! $child->included) {
                $child->update(['included' => true, 'auto_renew' => false]);
                $this->event($child, 'now_included', ['plan' => $parent->plan->code], $actor);
            }
        }

        foreach ($parent->addonLicenses()->where('included', true)->get() as $child) {
            if (! $included->contains('id', $child->addon_id)) {
                if ($child->status !== License::REVOKED) {
                    $child->update(['included' => false, 'status' => License::SUSPENDED, 'status_reason' => 'Not included in the '.$parent->plan?->name.' plan']);
                    $this->event($child, 'suspended', ['reason' => 'No longer included in the plan'], $actor);
                }

                continue;
            }
            $child->fill([
                'billing_type' => $parent->billing_type, 'expires_at' => $parent->expires_at, 'support_until' => $parent->support_until,
                'max_version' => null, 'status' => $parent->status, 'status_reason' => $parent->status_reason, 'pending_until' => $parent->pending_until,
            ]);
            if ($child->isDirty()) {
                $child->save();
            }
        }
    }

    // ------------------------------------------------------------ Free trials

    /**
     * Start a free trial of a plan: a license with billing_type "trial" that ends after the plan's
     * trial days, with the plan's trial limits. One per organisation.
     *
     * @return array{0: License, 1: string}
     */
    public function createTrial(Organisation $org, Plan $plan, string $actor): array
    {
        if (! $plan->offersTrial()) {
            throw new LicenseException('no_trial', 'The '.$plan->name.' plan has no free trial.', 422);
        }
        if ($org->trial_used_at) {
            throw new LicenseException('trial_used', 'Your account has already used its free trial. Buy a license to keep using '.setting('branding.product_name', 'LabelDesk').'.', 422);
        }

        $key = LicenseKey::generate();
        $limits = $plan->trialLimits();
        $license = DB::transaction(function () use ($org, $plan, $key, $limits, $actor) {
            $now = now();
            $license = License::create([
                'number' => $this->nextNumber(),
                'organisation_id' => $org->id,
                'plan_id' => $plan->id,
                'billing_type' => License::TRIAL,
                'status' => License::ACTIVE,
                'key_hash' => LicenseKey::hash($key),
                'key_encrypted' => $key,
                'key_last4' => substr($key, -4),
                'domains_included' => $limits['domains'],
                'domains_extra' => 0,
                'purchased_at' => $now,
                'expires_at' => $now->copy()->addDays($plan->trial_days),
                'support_until' => $now->copy()->addDays($plan->trial_days),
                'auto_renew' => false,
                'price_paid' => 0,
                'currency' => 'USD',
                'trial_limits' => $limits,
                'trial_started_at' => $now,
            ]);
            $org->forceFill(['trial_used_at' => $now])->save();
            $this->event($license, 'trial_started', ['plan' => $plan->code, 'days' => $plan->trial_days, 'limits' => Plan::summariseLimits($limits)], $actor);
            $this->syncAddons($license, $actor);

            return $license;
        });

        return [$license, $key];
    }

    /**
     * Buying a plan while a trial runs (or after it ended): the trial becomes the paid license.
     * Same key and domains; limits removed; a suspended (ended) trial is active again.
     *
     * @param  array{billing_type: string, domains_extra?: int, price_paid?: float, currency?: string, pending?: bool, auto_renew?: ?bool}  $options
     */
    public function convertTrial(License $trial, Plan $plan, array $options, string $actor): License
    {
        if (! $trial->isTrial() || $trial->status === License::REVOKED) {
            throw new LicenseException('not_trial', 'This license is not a trial.', 422);
        }
        $annual = $options['billing_type'] === 'annual';

        return $this->change($trial, 'trial_converted', function (License $l) use ($plan, $options, $annual) {
            $now = now();
            $pending = $options['pending'] ?? false;
            $l->fill([
                'plan_id' => $plan->id,
                'billing_type' => $options['billing_type'],
                'status' => $pending ? License::PENDING : License::ACTIVE,
                'status_reason' => null,
                'pending_until' => $pending ? $now->copy()->addDays(License::PENDING_DAYS) : null,
                'domains_included' => $plan->domains,
                'domains_extra' => max(0, (int) ($options['domains_extra'] ?? 0)),
                'purchased_at' => $now,
                'expires_at' => $annual ? $now->copy()->addYear() : null,
                'support_until' => $now->copy()->addYear(),
                'auto_renew' => $options['auto_renew'] ?? $annual,
                'price_paid' => (float) ($options['price_paid'] ?? 0),
                'currency' => strtoupper($options['currency'] ?? 'USD'),
                'trial_limits' => null,
                'max_version' => null,
            ]);
        }, $actor, ['plan' => $plan->code, 'billing_type' => $options['billing_type']]);
    }

    /** Trial over (+10 read-only days, as for an expired license): suspend it. */
    public function endTrial(License $trial, string $actor): License
    {
        return $this->change($trial, 'trial_ended', function (License $l) {
            $l->status = License::SUSPENDED;
            $l->status_reason = 'Free trial ended';
        }, $actor);
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
        $key = LicenseKey::generate($license->isAddon() ? 'LDAD' : 'LDSK');
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
            $this->syncAddons($license, $actor);

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
