<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A licensed add-on (optional LabelDesk module). Customers buy it annual or lifetime for a
 * main license, or get it free when their plan includes it. Each one is a separate License
 * with its own key (see License::$addon_id / $parent_id).
 */
class LicenseAddon extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['price_annual' => 'float', 'price_lifetime' => 'float', 'active' => 'boolean'];
    }

    /** Plans that include this add-on at no extra cost. */
    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class, 'license_addon_plan');
    }

    public function licenses(): HasMany
    {
        return $this->hasMany(License::class, 'addon_id');
    }

    public function releases(): HasMany
    {
        return $this->hasMany(Release::class, 'addon_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('active', true)->orderBy('sort')->orderBy('name');
    }

    public function usdPrice(string $billingType): ?float
    {
        return $billingType === 'lifetime' ? $this->price_lifetime : $this->price_annual;
    }

    public function includedIn(Plan $plan): bool
    {
        return $this->plans->contains('id', $plan->id);
    }
}
