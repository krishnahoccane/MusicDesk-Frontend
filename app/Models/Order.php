<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    protected $fillable = [
        'number', 'organisation_id', 'user_id', 'plan_id', 'kind', 'billing_type', 'extra_domains', 'lines',
        'currency', 'exchange_rate', 'subtotal', 'discount', 'tax_rate', 'tax', 'total', 'coupon',
        'gateway', 'gateway_order_id', 'gateway_payment_id', 'status', 'primary_domain',
        'billing_license_id', 'license_key', 'meta', 'paid_at',
    ];

    protected $hidden = ['license_key'];

    protected function casts(): array
    {
        return [
            'lines' => 'array',
            'meta' => 'array',
            'license_key' => 'encrypted',
            'paid_at' => 'datetime',
            'subtotal' => 'float',
            'discount' => 'float',
            'tax_rate' => 'float',
            'tax' => 'float',
            'total' => 'float',
            'exchange_rate' => 'float',
        ];
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    /** Amount in the smallest currency unit (paise, cents) for gateways. */
    public function amountMinor(): int
    {
        $decimals = Currency::find($this->currency)?->decimals ?? 2;

        return (int) round($this->total * (10 ** $decimals));
    }
}
