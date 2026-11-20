<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['code', 'name', 'symbol', 'decimals', 'rate', 'manual_rate', 'auto_update', 'charm_pricing', 'enabled', 'rate_updated_at'];

    protected function casts(): array
    {
        return [
            'rate' => 'float',
            'manual_rate' => 'float',
            'decimals' => 'integer',
            'auto_update' => 'boolean',
            'charm_pricing' => 'boolean',
            'enabled' => 'boolean',
            'rate_updated_at' => 'datetime',
        ];
    }

    public function scopeEnabled(Builder $q): Builder
    {
        return $q->where('enabled', true)->orderByRaw("code = 'USD' desc")->orderBy('code');
    }

    /** Effective rate: manual override wins over the auto-updated market rate. */
    public function effectiveRate(): float
    {
        return $this->code === 'USD' ? 1.0 : ($this->manual_rate ?: $this->rate);
    }
}
