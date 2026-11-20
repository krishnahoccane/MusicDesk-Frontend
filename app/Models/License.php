<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A LabelDesk license owned by an organisation. The key is stored as a SHA-256 hash
 * (license-server look-up) and APP_KEY-encrypted (revealed on request, recorded in
 * the history); only the last four characters are ever listed.
 */
class License extends Model
{
    public const PENDING = 'pending';

    public const ACTIVE = 'active';

    public const SUSPENDED = 'suspended';

    public const REVOKED = 'revoked';

    public const EXPIRING_DAYS = 30;

    public const PENDING_DAYS = 14;

    protected $guarded = ['id'];

    protected $hidden = ['key_hash', 'key_encrypted'];

    protected static function booted(): void
    {
        static::creating(fn (License $l) => $l->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'key_encrypted' => 'encrypted',
            'purchased_at' => 'datetime',
            'expires_at' => 'datetime',
            'support_until' => 'datetime',
            'pending_until' => 'datetime',
            'auto_renew' => 'boolean',
            'domains_included' => 'integer',
            'domains_extra' => 'integer',
            'price_paid' => 'float',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function activations(): HasMany
    {
        return $this->hasMany(LicenseActivation::class);
    }

    public function liveActivations(): HasMany
    {
        return $this->activations()->where('status', '!=', LicenseActivation::DEACTIVATED);
    }

    public function events(): HasMany
    {
        return $this->hasMany(LicenseEvent::class)->orderByDesc('id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $q;
        }

        return $q->where(fn ($w) => $w->where('number', 'like', "%$term%")
            ->orWhere('key_last4', strtoupper(substr($term, -4)))
            ->orWhereHas('organisation', fn ($o) => $o->where('name', 'like', "%$term%")->orWhere('email', 'like', "%$term%"))
            ->orWhereHas('activations', fn ($a) => $a->where('domain', 'like', '%'.strtolower($term).'%')));
    }

    public function domainLimit(): int
    {
        return $this->domains_included + $this->domains_extra;
    }

    /** Production activations that use a slot (pending or active). */
    public function domainsUsed(): int
    {
        return $this->activations()->where('environment', 'production')->whereIn('status', [LicenseActivation::PENDING, LicenseActivation::ACTIVE])->count();
    }

    public function maskedKey(): string
    {
        return 'LDSK-••••-••••-••••-'.$this->key_last4;
    }

    public function key(): string
    {
        return (string) $this->key_encrypted;
    }

    /** Stored status plus derived expiring / expired: pending | active | expiring | expired | suspended | revoked. */
    public function displayStatus(?Carbon $now = null): string
    {
        $now ??= now();
        if (in_array($this->status, [self::SUSPENDED, self::REVOKED], true)) {
            return $this->status;
        }
        if ($this->status === self::PENDING) {
            return $this->pending_until && $this->pending_until->lt($now) ? 'expired' : self::PENDING;
        }
        if ($this->expires_at) {
            if ($this->expires_at->lte($now)) {
                return 'expired';
            }
            if ($this->expires_at->lte($now->copy()->addDays(self::EXPIRING_DAYS))) {
                return 'expiring';
            }
        }

        return self::ACTIVE;
    }

    /** May installs run? Expired annual licenses keep running (no updates); suspended, revoked and lapsed pending ones don't. */
    public function allowsUse(): bool
    {
        if (in_array($this->status, [self::SUSPENDED, self::REVOKED], true)) {
            return false;
        }

        return ! ($this->status === self::PENDING && $this->pending_until && $this->pending_until->isPast());
    }

    public function supportExpired(): bool
    {
        return $this->support_until !== null && $this->support_until->isPast();
    }

    public function daysLeft(): ?int
    {
        return $this->expires_at ? (int) floor(now()->diffInDays($this->expires_at, false)) : null;
    }

    /** Newest version this license may install: anything released before support ended. */
    public function maxVersion(): ?string
    {
        if ($this->max_version) {
            return $this->max_version;
        }
        if (! $this->supportExpired()) {
            return null;   // every version
        }

        return Release::query()->where('published', true)->where('released_at', '<=', $this->support_until)
            ->orderByDesc('released_at')->value('version');
    }

    public function mayDownload(Release $release): bool
    {
        return $this->allowsUse() && ($this->support_until === null || $release->released_at->lte($this->support_until));
    }
}
