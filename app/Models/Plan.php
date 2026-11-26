<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $fillable = ['code', 'name', 'blurb', 'price_annual', 'price_lifetime', 'domains', 'extra_domain_price', 'features', 'featured', 'active', 'sort',
        'trial_enabled', 'trial_days', 'trial_limits'];

    /** Limits a free trial can carry (null / missing = no limit). LabelDesk enforces them (token claim "limits"). */
    public const TRIAL_LIMITS = [
        'users' => 'Users',
        'artists' => 'Artists',
        'releases' => 'Releases',
        'tracks' => 'Tracks',
    ];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'trial_enabled' => 'boolean',
            'trial_days' => 'integer',
            'trial_limits' => 'array',
            'featured' => 'boolean',
            'active' => 'boolean',
            'price_annual' => 'float',
            'price_lifetime' => 'float',
            'extra_domain_price' => 'float',
        ];
    }

    /** Licensed add-ons included free with this plan. */
    public function includedAddons(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(LicenseAddon::class, 'license_addon_plan');
    }

    public function orders(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('active', true)->orderBy('sort');
    }

    public function offersTrial(): bool
    {
        return $this->trial_enabled && $this->trial_days > 0;
    }

    /**
     * Trial limits with defaults: {domains: production domains (default 1), users|artists|releases|tracks: int|null,
     * addons: bool (plan's included add-ons during the trial), note: ?string (shown to customers)}.
     */
    public function trialLimits(): array
    {
        $l = $this->trial_limits ?? [];

        return [
            'domains' => max(1, (int) ($l['domains'] ?? 1)),
            ...collect(self::TRIAL_LIMITS)->mapWithKeys(fn ($label, $k) => [$k => isset($l[$k]) && $l[$k] !== '' ? (int) $l[$k] : null])->all(),
            'addons' => (bool) ($l['addons'] ?? false),
            'note' => $l['note'] ?? null,
        ];
    }

    /** "1 production domain · 3 users · 50 releases" for the pricing page and portal. */
    public function trialSummary(): string
    {
        return self::summariseLimits($this->trialLimits());
    }

    public static function summariseLimits(array $l): string
    {
        $parts = [($l['domains'] ?? 1).' production '.\Illuminate\Support\Str::plural('domain', (int) ($l['domains'] ?? 1))];
        foreach (self::TRIAL_LIMITS as $k => $label) {
            if (($l[$k] ?? null) !== null) {
                $parts[] = number_format($l[$k]).' '.strtolower($l[$k] === 1 ? \Illuminate\Support\Str::singular($label) : $label);
            }
        }
        $parts[] = ($l['addons'] ?? false) ? 'add-ons included' : 'no add-ons';

        return implode(' · ', $parts);
    }

    /** USD list price for a billing type. */
    public function usdPrice(string $billingType): ?float
    {
        return $billingType === 'lifetime' ? $this->price_lifetime : $this->price_annual;
    }

    /** Extra domains on a lifetime license are a one-time 2.5× the yearly price. */
    public function usdExtraDomainPrice(string $billingType): float
    {
        return $this->extra_domain_price * ($billingType === 'lifetime' ? 2.5 : 1);
    }
}
