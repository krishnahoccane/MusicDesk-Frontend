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
        'license_id', 'meta', 'paid_at', 'refund_reference', 'refund_reason', 'refunded_at',
    ];

    /** Order kinds: buying a new license, or changing an existing one. */
    public const KINDS = [
        'new_license' => 'New license',
        'renewal' => 'Renewal',
        'updates_renewal' => 'Updates & support renewal',
        'extra_domains' => 'Extra domains',
        'change_plan' => 'Upgrade',
        'convert_lifetime' => 'Switch to lifetime',
        'addon' => 'Add-on',
    ];

    protected function casts(): array
    {
        return [
            'lines' => 'array',
            'meta' => 'array',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
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

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? self::KINDS['new_license'];
    }

    public function isNewLicense(): bool
    {
        return in_array($this->kind, ['new_license', 'license'], true);
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
