<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** A domain a license may run on: pre-approved (pending), active (an install checked in) or deactivated. */
class LicenseActivation extends Model
{
    public const PENDING = 'pending';

    public const ACTIVE = 'active';

    public const DEACTIVATED = 'deactivated';

    public const ONLINE_HOURS = 26;   // installs check in every 24 h

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(fn (LicenseActivation $a) => $a->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'last_check_in_at' => 'datetime',
            'activated_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'check_in_requested' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    public function isOnline(): bool
    {
        return $this->status === self::ACTIVE && $this->last_check_in_at && $this->last_check_in_at->gt(now()->subHours(self::ONLINE_HOURS));
    }

    public function maskedIp(): ?string
    {
        if (! $this->ip) {
            return null;
        }
        if (str_contains($this->ip, ':')) {
            return implode(':', array_slice(explode(':', $this->ip), 0, 3)).':…';
        }
        $parts = explode('.', $this->ip);

        return count($parts) === 4 ? "{$parts[0]}.{$parts[1]}.xx.xx" : $this->ip;
    }
}
