<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organisation extends Model
{
    protected $fillable = ['name', 'email', 'country', 'vat_id', 'address', 'currency', 'timezone', 'notes'];

    protected function casts(): array
    {
        return ['address' => 'array'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function licenses(): HasMany
    {
        return $this->hasMany(License::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function initials(): string
    {
        return collect(preg_split('/\s+/', $this->name))->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('');
    }

    /** Buyer details as printed on invoices. */
    public function invoiceSnapshot(): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'country' => $this->country,
            'vat_id' => $this->vat_id,
            'address' => $this->address,
        ];
    }
}
