<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'organisation_id', 'role', 'timezone', 'currency', 'country'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'invited_at' => 'datetime',
            'invite_accepted_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function adminRole(): BelongsTo
    {
        return $this->belongsTo(AdminRole::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /** Team member with access to an admin area (see AdminRole::PERMISSIONS). */
    public function canAdmin(string $permission): bool
    {
        return $this->is_admin && (bool) $this->adminRole?->allows($permission);
    }

    public function isOwner(): bool
    {
        return $this->is_admin && (bool) $this->adminRole?->is_owner;
    }

    /** Invited to the team but hasn't set a password yet. */
    public function invitePending(): bool
    {
        return $this->invited_at !== null && $this->invite_accepted_at === null;
    }

    /** Emails of team members who can see an admin area (e.g. who gets security alerts or new tickets). */
    public static function staffEmails(string $permission): array
    {
        return static::query()->with('adminRole')->where('is_admin', true)->whereNotNull('invite_accepted_at')->get()
            ->filter(fn (User $u) => $u->canAdmin($permission))->pluck('email')->filter()->unique()->values()->all();
    }

    public function initials(): string
    {
        return collect(explode(' ', $this->name))->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('') ?: 'U';
    }
}
