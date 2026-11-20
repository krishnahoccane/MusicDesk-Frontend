<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** A LabelDesk version customers can download (file kept privately, served through signed links). */
class Release extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['released_at' => 'datetime', 'notes' => 'array', 'requirements' => 'array', 'published' => 'boolean', 'size_bytes' => 'integer'];
    }

    public function scopePublished(Builder $q): Builder
    {
        return $q->where('published', true)->orderByDesc('released_at');
    }

    public static function latest(): ?self
    {
        return static::published()->where('channel', 'stable')->first();
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
        return 'labeldesk-'.$this->version.'.zip';
    }
}
