<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One download of a release package: over the air by a LabelDesk install (ota) or from the customer portal. */
class ReleaseDownload extends Model
{
    public const UPDATED_AT = null;

    public const OTA = 'ota';

    public const PORTAL = 'portal';

    protected $guarded = ['id'];

    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }
}
