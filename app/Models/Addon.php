<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Addon extends Model
{
    protected $fillable = ['code', 'name', 'description', 'icon', 'price', 'interval', 'price_from', 'checkout', 'active', 'sort'];

    protected function casts(): array
    {
        return ['price' => 'float', 'price_from' => 'boolean', 'checkout' => 'boolean', 'active' => 'boolean'];
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('active', true)->orderBy('sort');
    }
}
