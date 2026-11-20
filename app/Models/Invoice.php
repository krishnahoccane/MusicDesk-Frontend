<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    protected $fillable = [
        'number', 'organisation_id', 'order_id', 'currency', 'exchange_rate', 'lines', 'subtotal', 'discount',
        'tax_rate', 'tax', 'total', 'status', 'seller', 'buyer', 'payment_method', 'issued_at', 'due_at', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'lines' => 'array',
            'seller' => 'array',
            'buyer' => 'array',
            'issued_at' => 'datetime',
            'due_at' => 'datetime',
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

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function description(): string
    {
        return $this->lines[0]['description'] ?? 'Invoice';
    }
}
