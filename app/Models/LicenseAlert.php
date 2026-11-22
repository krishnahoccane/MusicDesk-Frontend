<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A domain whose license check failed: reported by a LabelDesk install
 * (POST /api/license/v1/report) or refused by this license server.
 * One row per domain; repeated failures raise `attempts`.
 */
class LicenseAlert extends Model
{
    public const OPEN = 'open';

    public const BLOCKED = 'blocked';

    public const ISSUED = 'issued';

    public const DISMISSED = 'dismissed';

    /** Plain-English labels for the reasons LabelDesk and the server send. */
    public const REASONS = [
        'no_license' => 'Running without a license',
        'invalid_key' => 'Unknown license key',
        'invalid_token' => 'Forged or damaged license token',
        'token_expired' => 'License token expired',
        'signature_invalid' => 'License file signature invalid',
        'domain_mismatch' => 'Running on a domain the license doesn’t cover',
        'domain_blocked' => 'Blocked domain tried to activate',
        'domain_in_use' => 'Domain already licensed to someone else',
        'license_revoked' => 'Revoked license still in use',
        'license_suspended' => 'Suspended license still in use',
        'license_expired' => 'Expired license still in use',
        'activation_not_found' => 'Install not activated',
        'activation_deactivated' => 'Deactivated domain still in use',
        'files_tampered' => 'Core files modified (integrity check failed)',
        'check_in_failed' => 'Could not reach the license server',
        'other' => 'Other license failure',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'reasons' => 'array',
            'details' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'notified_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        $term = trim((string) $term);

        return $term === '' ? $q : $q->where(fn ($w) => $w->where('domain', 'like', "%{$term}%")->orWhere('ip', 'like', "%{$term}%"));
    }

    public static function isBlocked(string $domain): bool
    {
        return static::query()->where('domain', $domain)->where('status', self::BLOCKED)->exists();
    }

    public static function reasonLabel(?string $reason): string
    {
        return self::REASONS[$reason] ?? ucfirst(str_replace('_', ' ', (string) $reason));
    }

    /** Is the domain live on a license right now? Unknown domains get "Issue license". */
    public function knownActivation(): ?LicenseActivation
    {
        return LicenseActivation::query()->with('license.organisation')->where('domain', $this->domain)
            ->where('status', '!=', LicenseActivation::DEACTIVATED)->first();
    }

    public function badge(): string
    {
        return match ($this->status) {
            self::OPEN => 'badge-coral',
            self::BLOCKED => 'badge-ink',
            self::ISSUED => 'badge-mint',
            default => 'badge-ink',
        };
    }
}
