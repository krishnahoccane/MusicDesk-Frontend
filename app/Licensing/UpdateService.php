<?php

namespace App\Licensing;

use App\Models\License;
use App\Models\LicenseActivation;
use App\Models\LicenseAddon;
use App\Models\Release;
use App\Models\ReleaseDownload;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Over-the-air updates for LabelDesk 4.3.3+ (Administration → Updates there):
 * POST /updates/check and POST /updates/download (docs/licensing.md).
 */
class UpdateService
{
    public const DOWNLOADS_PER_HOUR = 10;

    public function __construct(private ActivationService $activations, private LicenseService $licenses) {}

    /**
     * Newer LabelDesk and add-on releases for an install. A release outside the updates period is
     * still returned (downloadable false, with a reason for the administrator).
     *
     * @param  array{version: string, php_version?: ?string, addons?: list<array{addon: string, version?: ?string, token?: ?string}>}  $install
     */
    public function check(string $token, array $install, ?string $ip): array
    {
        [$license, $activation] = $this->activations->fromToken($token, allowExpired: true);
        $this->activations->requireNotBlocked($activation->domain);

        $version = substr((string) $install['version'], 0, 20);
        $activation->forceFill(array_filter([
            'update_checked_at' => now(),
            'update_check_version' => $version,
            'version' => $version,
            'php_version' => isset($install['php_version']) ? substr((string) $install['php_version'], 0, 20) : null,
            'ip' => $ip,
        ], fn ($v) => $v !== null))->save();

        $core = Release::newestAfter(null, $version);
        $addons = [];
        foreach ($install['addons'] ?? [] as $installed) {
            $addon = LicenseAddon::query()->where('code', (string) ($installed['addon'] ?? ''))->first();
            $release = $addon ? Release::newestAfter($addon->id, (string) ($installed['version'] ?? '0.0.0')) : null;
            if ($release) {
                $addons[$addon->code] = $this->offer($release, $this->addonRefusal($addon, $installed['token'] ?? null, $license, $activation, $release));
            }
        }

        return [
            'labeldesk' => $core ? $this->offer($core, $license->updateRefusal($core)) : null,
            'addons' => (object) $addons,   // {} rather than [] when empty
        ];
    }

    /**
     * Checks for POST /updates/download. Returns the release whose package to stream and logs the download.
     *
     * @param  array{product: string, version: string, addon_token?: ?string}  $request
     *
     * @throws LicenseException
     */
    public function download(string $token, array $request, ?string $ip): Release
    {
        [$license, $activation] = $this->activations->fromToken($token, allowExpired: true);
        $this->activations->requireNotBlocked($activation->domain);

        $holder = $license;   // the license whose updates period applies
        $addon = null;
        if ($request['product'] !== 'labeldesk') {
            $addon = LicenseAddon::query()->where('code', $request['product'])->first()
                ?? throw new LicenseException('release_not_found', 'There is no such product.', 404, 'product');
            $holder = $this->addonLicense($addon, $request['addon_token'] ?? null, $license, $activation);
        }

        $release = Release::query()->forProduct($addon?->id)->where('version', $request['version'])->where('published', true)->first();
        if (! $release || ! $release->signed || ! $release->hasFile()) {
            throw new LicenseException('release_not_found', 'Version '.$request['version'].' is not available for automatic update.', 404, 'version');
        }

        if ($refusal = $holder->updateRefusal($release)) {
            $status = $holder->displayStatus();
            if ($holder->isAddon() && $holder->parent && ! $holder->parent->allowsUse()) {
                $status = $holder->parent->displayStatus();
            }
            $usable = $holder->allowsUse() && $status !== 'expired';
            throw new LicenseException($usable ? 'updates_expired' : 'license_'.(in_array($status, ['suspended', 'revoked'], true) ? $status : 'expired'), $refusal, 403);
        }

        $key = 'ota-download:'.$activation->uuid;
        if (RateLimiter::tooManyAttempts($key, self::DOWNLOADS_PER_HOUR)) {
            throw new LicenseException('rate_limited', 'Too many downloads from this site. Try again in '.ceil(RateLimiter::availableIn($key) / 60).' minutes.', 429);
        }
        RateLimiter::hit($key, 3600);

        ReleaseDownload::create([
            'release_id' => $release->id, 'license_id' => $holder->id, 'license_activation_id' => $activation->id,
            'domain' => $activation->domain, 'channel' => ReleaseDownload::OTA, 'ip' => $ip,
        ]);
        $this->licenses->event($holder, 'release_downloaded', ['version' => $release->version, 'domain' => $activation->domain, 'ip' => $ip, 'channel' => 'ota'], 'license server');

        return $release;
    }

    // ------------------------------------------------------------ internals

    /** @return array<string, mixed> */
    private function offer(Release $release, ?string $refusal): array
    {
        return [
            'version' => $release->version,
            'released_at' => $release->released_at->toDateString(),
            'changelog' => $release->changelogMarkdown(),
            ...($release->addon_id ? ['requires_labeldesk' => $release->requires_labeldesk] : ['requires_php' => $release->requires_php]),
            'size' => $release->size_bytes,
            'sha256' => $release->sha256,
            'downloadable' => $refusal === null,
            'reason' => $refusal,
        ];
    }

    /** Why the add-on key sent with /updates/check can't get $release, or null. */
    private function addonRefusal(LicenseAddon $addon, ?string $token, License $license, LicenseActivation $activation, Release $release): ?string
    {
        if (! $token) {
            return "Enter your {$addon->name} key under Administration → Add-ons to get updates. You find it in your MusicDesk account under Licenses › Add-ons.";
        }
        try {
            $holder = $this->addonLicense($addon, $token, $license, $activation);
        } catch (LicenseException $e) {
            return "Your {$addon->name} key can’t get updates on this site: ".$e->getMessage();
        }

        return $holder->updateRefusal($release);
    }

    /**
     * The add-on license behind an add-on token, checked against the main license's install:
     * same add-on (addon_mismatch) and a domain live on the main license (addon_domain_not_licensed).
     *
     * @throws LicenseException
     */
    private function addonLicense(LicenseAddon $addon, ?string $token, License $license, LicenseActivation $activation): License
    {
        if (! $token) {
            throw new LicenseException('addon_mismatch', "Send the {$addon->name} add-on token (addon_token).", 409, 'addon_token');
        }
        [$addonLicense, $addonActivation] = $this->activations->fromAddonToken($token);
        if ((int) $addonLicense->addon_id !== (int) $addon->id) {
            throw new LicenseException('addon_mismatch', 'This add-on token is for '.($addonLicense->addon?->name ?? 'another add-on').', not '.$addon->name.'.', 409, 'addon_token');
        }
        if ((int) $addonLicense->parent_id !== (int) $license->id || $addonActivation->domain !== $activation->domain) {
            throw new LicenseException('addon_domain_not_licensed', "The {$addon->name} key belongs to another license or domain. Add-ons run only on their main license’s domains.", 409, 'addon_token');
        }

        return $addonLicense;
    }
}
