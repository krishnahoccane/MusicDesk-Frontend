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

    /** billing_type of a free trial (others: annual, lifetime). */
    public const TRIAL = 'trial';

    /** After a license (or trial) expires LabelDesk is read-only this many days, then locked. */
    public const READ_ONLY_DAYS = 10;

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
            'included' => 'boolean',
            'domains_included' => 'integer',
            'domains_extra' => 'integer',
            'price_paid' => 'float',
            'trial_limits' => 'array',
            'trial_started_at' => 'datetime',
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

    /** Set on add-on licenses: which add-on this key unlocks. */
    public function addon(): BelongsTo
    {
        return $this->belongsTo(LicenseAddon::class, 'addon_id');
    }

    /** Set on add-on licenses: the main license it belongs to. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(License::class, 'parent_id');
    }

    /** Add-on licenses attached to this main license. */
    public function addonLicenses(): HasMany
    {
        return $this->hasMany(License::class, 'parent_id')->orderBy('id');
    }

    public function isTrial(): bool
    {
        return $this->billing_type === self::TRIAL;
    }

    public function trialDaysLeft(): ?int
    {
        return $this->isTrial() && $this->expires_at ? max(0, (int) ceil(now()->diffInHours($this->expires_at, false) / 24)) : null;
    }

    public function isAddon(): bool
    {
        return $this->addon_id !== null;
    }

    /** Main licenses only (no add-on keys). */
    public function scopeCore(Builder $q): Builder
    {
        return $q->whereNull('addon_id');
    }

    /** "LabelDesk Business" or "DDEX delivery (add-on)". */
    public function productName(): string
    {
        return $this->isAddon() ? ($this->addon?->name ?? 'Add-on').' add-on' : setting('branding.product_name', 'LabelDesk').' '.($this->plan?->name ?? '');
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
        if ($this->isAddon() && $this->parent) {
            return $this->parent->domainLimit();   // an add-on runs on the main license's domains
        }

        return $this->domains_included + $this->domains_extra;
    }

    /** Production activations that use a slot (pending or active). */
    public function domainsUsed(): int
    {
        return $this->activations()->where('environment', 'production')->whereIn('status', [LicenseActivation::PENDING, LicenseActivation::ACTIVE])->count();
    }

    public function maskedKey(): string
    {
        return ($this->isAddon() ? 'LDAD' : 'LDSK').'-••••-••••-••••-'.$this->key_last4;
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
        if ($this->isAddon() && $this->parent && ! $this->parent->allowsUse()) {
            return false;   // an add-on can't run without its main license
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

        // Highest version number released before support ended, compared as versions (4.3.10 > 4.3.9), not text or dates.
        return Release::query()->forProduct($this->addon_id)->where('published', true)->where('released_at', '<=', $this->support_until)
            ->pluck('version')->reduce(fn (?string $max, string $v) => $max === null || version_compare($v, $max, '>') ? $v : $max);
    }

    /** Is this version within the license's version cap (if any)? */
    public function allowsVersion(string $version): bool
    {
        $max = $this->maxVersion();

        return $max === null || version_compare($version, $max, '<=');
    }

    /**
     * Why this license may not install $release over the air (LabelDesk shows it to the
     * administrator as is), or null when it may: the license must be usable and not expired,
     * and the release must fall within its updates period and version cap.
     */
    public function updateRefusal(Release $release): ?string
    {
        $product = $this->isAddon() ? ($this->addon?->name ?? 'this add-on') : setting('branding.product_name', 'LabelDesk');
        $status = $this->displayStatus();
        if (! $this->allowsUse() || $status === 'expired') {
            if ($this->isAddon() && $this->parent && ! $this->parent->allowsUse()) {
                return "Your main license {$this->parent->number} is {$this->parent->displayStatus()}, so {$product} can’t be updated. Contact support.";
            }

            return match ($status) {
                'suspended' => "Your license {$this->number} is suspended".($this->status_reason ? " ({$this->status_reason})" : '').'. Contact support to get updates.',
                'revoked' => "Your license {$this->number} was revoked. Updates are not available.",
                default => "Your license {$this->number} for {$product} has expired. Renew it to get this version.",
            };
        }
        if ($this->support_until && $release->released_at->gt($this->support_until)) {
            return "Your updates period for {$product} ended on ".local_time($this->support_until)->format('j M Y').'. Renew it to get this version.';
        }
        if ($this->max_version && version_compare($release->version, $this->max_version, '>')) {
            return "Your license is limited to {$product} {$this->max_version}. Contact support to get this version.";
        }

        return null;
    }

    public function mayDownload(Release $release): bool
    {
        return (int) $release->addon_id === (int) $this->addon_id && $this->allowsUse()
            && ($this->support_until === null || $release->released_at->lte($this->support_until))
            && (! $this->max_version || version_compare($release->version, $this->max_version, '<='));
    }
}
