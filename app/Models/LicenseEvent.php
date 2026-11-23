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
            'domain_deactivated' => 'Domain deactivated: '.($this->details['domain'] ?? '').match ($this->details['reason'] ?? null) {
                'manual' => ' (manual, with emailed code)',
                'transfer' => ' (moved to a new domain)',
                'key_change' => ' (license key changed)',
                default => '',
            },
            'addon_added' => 'Add-on added: '.($this->details['addon'] ?? '').(! empty($this->details['included']) ? ' (included with the plan)' : '').(isset($this->details['number']) ? ', key '.$this->details['number'] : ''),
            'now_included' => 'Add-on now included with the '.ucfirst($this->details['plan'] ?? '').' plan',
            'deactivation_code_sent' => 'Deactivation code sent for '.($this->details['domain'] ?? '').(isset($this->details['sent_to']) ? ' to '.$this->details['sent_to'] : ''),
            'domain_activated' => 'Install activated on '.($this->details['domain'] ?? ''),
            'check_in_requested' => 'Check-in requested: '.($this->details['domain'] ?? ''),
            'offline_activation' => 'Offline activation file issued: '.($this->details['domain'] ?? ''),
            'order_refunded' => 'Order refunded',
            'expiry_notice' => 'Expiry reminder sent ('.($this->details['days'] ?? '').' days)',
            default => ucfirst(str_replace('_', ' ', $this->event)),
        };
    }
}
