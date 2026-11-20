<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LicenseEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'datetime'];
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    /** Human label for the timeline. */
    public function label(): string
    {
        return match ($this->event) {
            'created' => 'License created',
            'renewed' => 'Renewed for 12 months',
            'updates_renewed' => 'Updates & support renewed',
            'domains_added' => 'Extra domains added ('.($this->details['added'] ?? '').')',
            'plan_changed' => 'Plan changed'.(isset($this->details['to']) ? ' to '.ucfirst($this->details['to']) : ''),
            'converted_to_lifetime' => 'Switched to a lifetime license',
            'auto_renew_on' => 'Renewal reminders turned on',
            'auto_renew_off' => 'Renewal reminders turned off',
            'suspended' => 'Suspended',
            'reinstated' => 'Reinstated',
            'revoked' => 'Revoked',
            'payment_confirmed' => 'Bank transfer received, license active',
            'dates_changed' => 'Dates changed',
            'details_changed' => 'Details changed',
            'key_revealed' => 'Key revealed',
            'key_regenerated' => 'New key generated (old key stopped working)',
            'domain_added' => 'Domain added: '.($this->details['domain'] ?? ''),
            'domain_moved' => 'Domain moved: '.($this->details['from'] ?? '').' → '.($this->details['to'] ?? ''),
            'domain_deactivated' => 'Domain deactivated: '.($this->details['domain'] ?? ''),
            'domain_activated' => 'Install activated on '.($this->details['domain'] ?? ''),
            'check_in_requested' => 'Check-in requested: '.($this->details['domain'] ?? ''),
            'offline_activation' => 'Offline activation file issued: '.($this->details['domain'] ?? ''),
            'order_refunded' => 'Order refunded',
            'expiry_notice' => 'Expiry reminder sent ('.($this->details['days'] ?? '').' days)',
            default => ucfirst(str_replace('_', ' ', $this->event)),
        };
    }
}
