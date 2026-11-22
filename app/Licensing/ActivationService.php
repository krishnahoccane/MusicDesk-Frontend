<?php

namespace App\Licensing;

use App\Models\License;
use App\Models\LicenseActivation;
use App\Models\LicenseAlert;
use Illuminate\Support\Facades\DB;

/**
 * Domains and installs. Rules:
 *  - production activations (pending + active) ≤ included + extra domains
 *  - staging is free: staging.*, dev.*, test.*, *.test, *.local, *.localhost, localhost, or environment=staging
 *  - domains are normalised (lowercase, no scheme / path / port / www.)
 *  - a domain can be live on one license only
 *  - max 3 domain moves per license in 30 days
 *  - add-on licenses run only on domains that are live on their main license (no slots of
 *    their own), and lose a domain when the main license does
 */
class ActivationService
{
    public const MAX_MOVES_PER_30_DAYS = 3;

    public function __construct(private LicenseService $licenses, private LicenseToken $tokens) {}

    public static function normalise(string $domain): string
    {
        $d = strtolower(trim($domain));
        $d = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $d);
        $d = (string) preg_replace('#[/?\#].*$#', '', $d);
        $d = (string) preg_replace('#:\d+$#', '', $d);
        $d = (string) preg_replace('/^www\./', '', $d);

        return rtrim($d, '.');
    }

    public static function isStaging(string $domain): bool
    {
        return $domain === 'localhost'
            || (bool) preg_match('/^(staging|dev|test)\.|\.(test|local|localhost|invalid)$/', $domain)
            || (bool) filter_var($domain, FILTER_VALIDATE_IP);
    }

    public static function validDomain(string $domain): bool
    {
        return $domain === 'localhost' || (bool) filter_var($domain, FILTER_VALIDATE_IP)
            || (bool) preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,62}$/', $domain);
    }

    /** Pre-approve a domain (portal "Add domain" / admin). */
    public function add(License $license, string $domain, ?string $environment, string $actor): LicenseActivation
    {
        return DB::transaction(function () use ($license, $domain, $environment, $actor) {
            $license = License::query()->whereKey($license->id)->lockForUpdate()->firstOrFail();
            [$domain, $env] = $this->checkNew($license, $domain, $environment);

            $activation = $license->activations()->create(['domain' => $domain, 'environment' => $env, 'status' => LicenseActivation::PENDING]);
            $this->licenses->event($license, 'domain_added', ['domain' => $domain, 'environment' => $env], $actor);

            return $activation;
        });
    }

    public function move(LicenseActivation $activation, string $newDomain, string $actor): LicenseActivation
    {
        return DB::transaction(function () use ($activation, $newDomain, $actor) {
            $license = License::query()->whereKey($activation->license_id)->lockForUpdate()->firstOrFail();
            $moves = $license->events()->where('event', 'domain_moved')->where('created_at', '>=', now()->subDays(30))->count();
            if ($moves >= self::MAX_MOVES_PER_30_DAYS) {
                throw new LicenseException('move_limit_reached', 'This license has reached its limit of 3 domain moves in 30 days.');
            }

            $old = $activation->domain;
            $activation->update(['status' => LicenseActivation::DEACTIVATED, 'deactivated_at' => now()]);
            $this->cascadeDeactivate($license, $old, $actor);
            [$domain, $env] = $this->checkNew($license, $newDomain, $activation->environment);
            $new = $license->activations()->create(['domain' => $domain, 'environment' => $env, 'status' => LicenseActivation::PENDING]);
            $this->licenses->event($license, 'domain_moved', ['from' => $old, 'to' => $domain], $actor);

            return $new;
        });
    }

    public function deactivate(LicenseActivation $activation, string $actor): void
    {
        if ($activation->status === LicenseActivation::DEACTIVATED) {
            return;
        }
        $activation->update(['status' => LicenseActivation::DEACTIVATED, 'deactivated_at' => now()]);
        $this->licenses->event($activation->license, 'domain_deactivated', ['domain' => $activation->domain], $actor);
        $this->cascadeDeactivate($activation->license, $activation->domain, $actor);
    }

    /** A main license lost a domain: its add-ons lose it too. */
    private function cascadeDeactivate(License $license, string $domain, string $actor): void
    {
        if ($license->isAddon()) {
            return;
        }
        LicenseActivation::query()->with('license')->where('domain', $domain)->where('status', '!=', LicenseActivation::DEACTIVATED)
            ->whereIn('license_id', $license->addonLicenses()->pluck('id'))->get()
            ->each(function (LicenseActivation $a) use ($actor) {
                $a->update(['status' => LicenseActivation::DEACTIVATED, 'deactivated_at' => now()]);
                $this->licenses->event($a->license, 'domain_deactivated', ['domain' => $a->domain, 'reason' => 'Deactivated on the main license'], $actor);
            });
    }

    public function requestCheckIn(LicenseActivation $activation, string $actor): void
    {
        $activation->update(['check_in_requested' => true]);
        $this->licenses->event($activation->license, 'check_in_requested', ['domain' => $activation->domain], $actor);
    }

    // ------------------------------------------------------------ License server (LabelDesk installs)

    /** POST /api/license/v1/activate */
    public function activateInstall(string $key, array $install, ?string $ip): array
    {
        $domain = self::normalise((string) ($install['domain'] ?? ''));
        if (! self::validDomain($domain)) {
            throw new LicenseException('invalid_domain', 'That is not a valid domain.', 422, 'domain');
        }
        $this->requireNotBlocked($domain);

        $license = $this->licenses->findByKey($key) ?? throw new LicenseException('invalid_key', 'License key not recognised.', 404);
        $this->requireUsable($license);

        return $this->response($license->fresh(), $this->activateOn($license, $domain, $install, $ip));
    }

    /** Activate (or re-activate) a normalised, valid domain on a usable license. */
    private function activateOn(License $license, string $domain, array $install, ?string $ip): LicenseActivation
    {
        return DB::transaction(function () use ($license, $domain, $install, $ip) {
            $license = License::query()->whereKey($license->id)->lockForUpdate()->firstOrFail();
            $existing = $license->activations()->where('domain', $domain)->where('status', '!=', LicenseActivation::DEACTIVATED)->first();

            if (! $existing) {
                [$domain, $env] = $this->checkNew($license, $domain, $install['environment'] ?? null);
                $existing = $license->activations()->create(['domain' => $domain, 'environment' => $env, 'status' => LicenseActivation::PENDING]);
            }

            $first = $existing->status !== LicenseActivation::ACTIVE;
            $existing->fill([
                'status' => LicenseActivation::ACTIVE,
                'activated_at' => $existing->activated_at ?? now(),
                'instance_id' => isset($install['instance_id']) ? substr((string) $install['instance_id'], 0, 100) : $existing->instance_id,
            ])->save();
            $this->record($existing, $install, $ip);
            if ($first) {
                $this->licenses->event($license, 'domain_activated', ['domain' => $existing->domain], 'license server');
            }

            return $existing;
        });
    }

    /** POST /api/license/v1/check-in */
    public function checkIn(string $token, array $install, ?string $ip): array
    {
        [$license, $activation] = $this->fromToken($token, allowExpired: true);
        $this->requireNotBlocked($activation->domain);
        $this->requireUsable($license);
        $this->record($activation, $install, $ip);
        $activation->update(['check_in_requested' => false]);

        return $this->response($license, $activation->fresh());
    }

    /** POST /api/license/v1/deactivate */
    public function deactivateInstall(string $token): void
    {
        [, $activation] = $this->fromToken($token, allowExpired: true);
        $this->deactivate($activation, 'license server');
    }

    // ------------------------------------------------------------ Add-on keys (LabelDesk plugins)

    /**
     * POST /api/license/v1/addons/activate: a LabelDesk install activates a plugin with the
     * add-on's own key (LDAD-…). The key must belong to that add-on, and the domain must be live
     * on the add-on's main license (add-ons have no domain slots of their own).
     */
    public function activateAddon(string $key, string $addon, array $install, ?string $ip): array
    {
        $domain = self::normalise((string) ($install['domain'] ?? ''));
        if (! self::validDomain($domain)) {
            throw new LicenseException('invalid_domain', 'That is not a valid domain.', 422, 'domain');
        }
        $this->requireNotBlocked($domain);

        $license = $this->licenses->findByKey($key) ?? throw new LicenseException('invalid_key', 'License key not recognised.', 404);
        if (! $license->isAddon()) {
            throw new LicenseException('addon_mismatch', 'This is a main license key. Enter the add-on’s own key (LDAD-…) from your MusicDesk account.', 409, 'key');
        }
        if ($license->addon?->code !== $addon) {
            throw new LicenseException('addon_mismatch', 'This key is for the '.($license->addon?->name ?? 'another').' add-on.', 409, 'key');
        }
        $this->requireAddonUsable($license);

        return $this->addonResponse($license->fresh(), $this->activateOn($license, $domain, $install, $ip));
    }

    /** POST /api/license/v1/addons/check-in (accepts expired tokens, like the main check-in). */
    public function checkInAddon(string $token, array $install, ?string $ip): array
    {
        [$license, $activation] = $this->fromAddonToken($token);
        $this->requireNotBlocked($activation->domain);
        $this->requireAddonUsable($license);
        $this->record($activation, $install, $ip);
        $activation->update(['check_in_requested' => false]);

        return $this->addonResponse($license, $activation->fresh());
    }

    /** POST /api/license/v1/addons/deactivate: frees the domain on the add-on key. */
    public function deactivateAddon(string $token): void
    {
        [, $activation] = $this->fromAddonToken($token);
        $this->deactivate($activation, 'license server');
    }

    /**
     * Add-ons stop when their key (or its main license) may not be used, and also when the
     * add-on key has expired (LabelDesk itself turns read-only instead).
     */
    private function requireAddonUsable(License $license): void
    {
        $parent = $license->parent;
        if ($parent && ! $parent->allowsUse()) {
            $status = $parent->displayStatus();
            throw new LicenseException('license_'.$status, "The main license {$parent->number} is {$status}. Add-ons run only while it is usable.", 403);
        }
        $status = $license->displayStatus();
        if (! $license->allowsUse() || $status === 'expired') {
            throw new LicenseException('license_'.$status, 'This add-on key is '.$status.($license->status_reason ? ': '.$license->status_reason : '.'), 403);
        }
    }

    /** @return array{0: License, 1: LicenseActivation} */
    private function fromAddonToken(string $token): array
    {
        $claims = $this->tokens->verify($token, allowExpired: true);
        if (($claims['typ'] ?? null) !== 'addon') {
            throw new LicenseException('invalid_token', 'This is not an add-on token.', 401);
        }
        $activation = LicenseActivation::query()->where('uuid', $claims['act'] ?? '')->with('license.addon')->first();
        $license = $activation?->license;
        if (! $license || ! $license->isAddon() || $license->uuid !== ($claims['lic'] ?? null) || $license->addon?->code !== ($claims['addon'] ?? null)) {
            throw new LicenseException('activation_not_found', 'This add-on is not activated on this domain. Activate it again with its key.', 404);
        }
        if ($activation->status === LicenseActivation::DEACTIVATED) {
            throw new LicenseException('activation_deactivated', 'This domain was deactivated on the add-on key. Activate it again with the key.', 403);
        }

        return [$license, $activation];
    }

    /**
     * Add-on token claims (verified by LabelDesk's AddonLicenseService): iss, iat, exp,
     * typ "addon", addon, dom, act, status (active | expiring), expires (Unix or null), lic.
     */
    private function addonResponse(License $license, LicenseActivation $activation): array
    {
        // A pending (bank transfer) key works during its 14-day window: report it as active.
        $status = $license->displayStatus() === 'expiring' ? 'expiring' : 'active';
        $expires = $license->billing_type === 'lifetime' ? null : $license->expires_at;

        return [
            'token' => $this->tokens->issue([
                'typ' => 'addon',
                'addon' => $license->addon->code,
                'lic' => $license->uuid,
                'act' => $activation->uuid,
                'dom' => $activation->domain,
                'status' => $status,
                'expires' => $expires?->timestamp,
            ]),
            'status' => $status,
            'expires_at' => $expires?->utc()->format('Y-m-d\TH:i:s\Z'),
            'support_until' => $license->support_until?->utc()->format('Y-m-d\TH:i:s\Z'),
            'addon' => $license->addon->code,
        ];
    }

    /** Offline activation for servers without internet: a token valid 365 days, delivered as a file. */
    public function offlineActivation(License $license, string $domain, ?string $instanceId, string $actor): array
    {
        $this->requireUsable($license);
        $domain = self::normalise($domain);
        if (! self::validDomain($domain)) {
            throw new LicenseException('invalid_domain', 'That is not a valid domain.', 422, 'domain');
        }
        $this->requireNotBlocked($domain);

        $activation = DB::transaction(function () use ($license, $domain, $instanceId) {
            $existing = $license->activations()->where('domain', $domain)->where('status', '!=', LicenseActivation::DEACTIVATED)->first();
            if (! $existing) {
                [$domain, $env] = $this->checkNew($license, $domain, null);
                $existing = $license->activations()->create(['domain' => $domain, 'environment' => $env, 'status' => LicenseActivation::PENDING]);
            }
            $existing->update(['status' => LicenseActivation::ACTIVE, 'activated_at' => $existing->activated_at ?? now(), 'instance_id' => $instanceId ?: $existing->instance_id]);

            return $existing;
        });
        $this->licenses->event($license, 'offline_activation', ['domain' => $activation->domain], $actor);

        return ['token' => $this->tokenFor($license, $activation, LicenseToken::OFFLINE_TTL_DAYS), 'activation' => $activation];
    }

    // ------------------------------------------------------------ helpers

    /** @return array{0: string, 1: string} normalised domain and environment */
    private function checkNew(License $license, string $domain, ?string $environment): array
    {
        $domain = self::normalise($domain);
        if (! self::validDomain($domain)) {
            throw new LicenseException('invalid_domain', 'Enter a domain like portal.yourlabel.com.', 422, 'domain');
        }
        $this->requireNotBlocked($domain);
        $env = $environment === 'staging' || self::isStaging($domain) ? 'staging' : 'production';

        if ($license->activations()->where('domain', $domain)->where('status', '!=', LicenseActivation::DEACTIVATED)->exists()) {
            throw new LicenseException('domain_exists', 'That domain is already on this license.');
        }
        // One license per product per domain (LabelDesk itself, or one add-on).
        $elsewhere = LicenseActivation::query()->where('domain', $domain)->where('status', '!=', LicenseActivation::DEACTIVATED)
            ->where('license_id', '!=', $license->id)
            ->whereHas('license', fn ($l) => $license->addon_id ? $l->where('addon_id', $license->addon_id) : $l->whereNull('addon_id'))->exists();
        if ($elsewhere) {
            throw new LicenseException('domain_in_use', 'That domain is already active on another license.');
        }
        if ($license->isAddon()) {
            $main = $license->parent?->activations()->where('domain', $domain)->where('status', '!=', LicenseActivation::DEACTIVATED)->first();
            if (! $main) {
                throw new LicenseException('addon_domain_not_licensed', "Add {$domain} to the main license {$license->parent?->number} first. Add-ons run on its domains.", 409, 'domain');
            }

            return [$domain, $main->environment];
        }
        if ($env === 'production' && $license->domainsUsed() >= $license->domainLimit()) {
            throw new LicenseException('domain_limit_reached', 'All '.$license->domainLimit().' production domains on this license are in use. Deactivate one or buy an extra domain.');
        }

        return [$domain, $env];
    }

    private function requireNotBlocked(string $domain): void
    {
        if (LicenseAlert::isBlocked($domain)) {
            throw new LicenseException('domain_blocked', 'This domain is blocked from using '.setting('branding.product_name', 'LabelDesk').'. Contact support.', 403, 'domain');
        }
    }

    private function requireUsable(License $license): void
    {
        if (! $license->allowsUse()) {
            $status = $license->displayStatus();
            throw new LicenseException('license_'.$status, 'This license is '.$status.($license->status_reason ? ': '.$license->status_reason : '.'), 403);
        }
    }

    private function record(LicenseActivation $activation, array $install, ?string $ip): void
    {
        $activation->update(array_filter([
            'version' => isset($install['version']) ? substr((string) $install['version'], 0, 20) : null,
            'php_version' => isset($install['php_version']) ? substr((string) $install['php_version'], 0, 20) : null,
            'ip' => $ip,
            'last_check_in_at' => now(),
        ], fn ($v) => $v !== null));
    }

    /** @return array{0: License, 1: LicenseActivation} */
    private function fromToken(string $token, bool $allowExpired): array
    {
        $claims = $this->tokens->verify($token, $allowExpired);
        if (($claims['typ'] ?? null) === 'addon') {
            throw new LicenseException('invalid_token', 'This is an add-on token. Use the /addons endpoints.', 401);
        }
        $activation = LicenseActivation::query()->where('uuid', $claims['act'] ?? '')->with('license')->first();
        if (! $activation || $activation->license?->uuid !== ($claims['lic'] ?? null)) {
            throw new LicenseException('activation_not_found', 'This install is not activated. Activate it again with the license key.', 404);
        }
        if ($activation->status === LicenseActivation::DEACTIVATED) {
            throw new LicenseException('activation_deactivated', 'This domain was deactivated. Activate it again with the license key.', 403);
        }

        return [$activation->license, $activation];
    }

    private function tokenFor(License $license, LicenseActivation $activation, int $ttlDays = LicenseToken::TTL_DAYS): string
    {
        return $this->tokens->issue([
            'lic' => $license->uuid,
            'act' => $activation->uuid,
            'dom' => $activation->domain,
            'env' => $activation->environment,
            'plan' => $license->plan?->code,
            'status' => $license->displayStatus(),
            'support_until' => $license->support_until?->timestamp,
            // License expiry (annual; null = lifetime). LabelDesk is read-only for
            // 10 days after this date and locked afterwards until renewed.
            'expires' => $license->billing_type === 'lifetime' ? null : $license->expires_at?->timestamp,
            'max_version' => $license->maxVersion(),
            // Add-on keys: which add-on, and the main license it belongs to.
            'addon' => $license->addon?->code,
            'parent' => $license->parent?->uuid,
        ], $ttlDays);
    }

    private function response(License $license, LicenseActivation $activation): array
    {
        $messages = [];
        if ($license->displayStatus() === 'expired' && $license->billing_type === 'annual') {
            $lock = $license->expires_at?->copy()->addDays(10);
            $messages[] = 'Your annual license has expired. LabelDesk is read-only'.($lock ? ' and will be locked after '.$lock->toFormattedDateString() : ' for 10 days and then locked').' unless you renew.';
        } elseif ($license->status === License::PENDING) {
            $messages[] = 'Awaiting payment. This license works until '.$license->pending_until?->toDateString().'.';
        }

        return [
            'token' => $this->tokenFor($license, $activation),
            'status' => $license->displayStatus(),
            'support_until' => $license->support_until?->utc()->format('Y-m-d\TH:i:s\Z'),
            'expires_at' => $license->billing_type === 'lifetime' ? null : $license->expires_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'max_version' => $license->maxVersion(),
            'check_in_requested' => (bool) $activation->check_in_requested,
            'addon' => $license->addon?->code,
            'messages' => $messages,
        ];
    }
}
