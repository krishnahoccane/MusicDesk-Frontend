<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer support ticket. Status: open (waiting on support),
 * awaiting (waiting on the customer) or resolved.
 */
class SupportTicket extends Model
{
    public const OPEN = 'open';

    public const AWAITING = 'awaiting';

    public const RESOLVED = 'resolved';

    public const CATEGORIES = [
        'installation' => 'Installation',
        'updating' => 'Updating',
        'branding' => 'Branding & theme',
        'bug' => 'Bug report',
        'licensing' => 'Licensing & billing',
        'feature' => 'Feature request',
        'other' => 'Something else',
    ];

    public const PRIORITIES = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High: production is down'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['last_reply_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'number';
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class)->orderBy('id');
    }

    /** Messages the customer may see (no internal notes). */
    public function publicMessages(): HasMany
    {
        return $this->messages()->where('internal', false);
    }

    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        $term = trim((string) $term);

        return $term === '' ? $q : $q->where(fn ($w) => $w->where('number', 'like', "%{$term}%")->orWhere('subject', 'like', "%{$term}%")
            ->orWhere('domain', 'like', "%{$term}%")->orWhereHas('organisation', fn ($o) => $o->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%")));
    }

    public function statusLabel(bool $forStaff = false): string
    {
        return match ($this->status) {
            self::OPEN => $forStaff ? 'Needs reply' : 'Open',
            self::AWAITING => $forStaff ? 'Awaiting customer' : 'Awaiting you',
            default => 'Resolved',
        };
    }

    public function badge(bool $forStaff = false): string
    {
        return match ($this->status) {
            self::OPEN => $forStaff ? 'badge-coral' : 'badge-volt',
            self::AWAITING => 'badge-amber',
            default => 'badge-mint',
        };
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? ucfirst($this->category);
    }

    /** First reply target from the plan: Business is 1 business day, others 2. */
    public function slaHours(): int
    {
        return ($this->license?->plan?->code === 'business' || $this->priority === 'high') ? 24 : 48;
    }

    public function overdue(): bool
    {
        return $this->status === self::OPEN && $this->last_reply_at && $this->last_reply_at->lt(now()->subHours($this->slaHours()));
    }
}
