<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** A LabelDesk (or add-on, when addon_id is set) version customers can download (file kept privately). */
class Release extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['released_at' => 'datetime', 'notes' => 'array', 'requirements' => 'array', 'published' => 'boolean', 'size_bytes' => 'integer'];
    }

    public function addon(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(LicenseAddon::class, 'addon_id');
    }

    /** LabelDesk itself (not an add-on). */
    public function scopeCore(Builder $q): Builder
    {
        return $q->whereNull('addon_id');
    }

    public function scopeForProduct(Builder $q, ?int $addonId): Builder
    {
        return $addonId ? $q->where('addon_id', $addonId) : $q->whereNull('addon_id');
    }

    public function scopePublished(Builder $q): Builder
    {
        return $q->where('published', true)->orderByDesc('released_at');
    }

    public static function latest(): ?self
    {
        return static::newestFirst(static::published()->core()->where('channel', 'stable')->get())->first();
    }

    /**
     * Newest first: by release date, then by version number compared as a version
     * (4.3.10 > 4.3.9, and 4.3.2 > 4.3.1 when both shipped the same day).
     */
    public static function newestFirst(\Illuminate\Support\Collection $releases): \Illuminate\Support\Collection
    {
        return $releases->sort(fn (self $a, self $b) => ($b->released_at <=> $a->released_at) ?: version_compare($b->version, $a->version))->values();
    }

    public static function latestVersion(): string
    {
        static $v;

        try {
            return $v ??= (static::latest()?->version ?? '—');
        } catch (\Throwable) {
            return '—';   // before installation
        }
    }

    public function hasFile(): bool
    {
        return $this->file_path && \Illuminate\Support\Facades\Storage::disk('local')->exists($this->file_path);
    }

    public function fileName(): string
    {
        return ($this->addon ? 'labeldesk-'.$this->addon->code : 'labeldesk').'-'.$this->version.'.zip';
    }
}
