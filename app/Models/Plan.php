<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $fillable = ['code', 'name', 'blurb', 'price_annual', 'price_lifetime', 'domains', 'extra_domain_price', 'features', 'featured', 'active', 'sort'];

    protected function casts(): array
    {
        return [
            'features' => 'array',
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
