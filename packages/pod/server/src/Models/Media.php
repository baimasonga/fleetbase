<?php

namespace Fleetbase\Pod\Models;

use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class Media extends Model
{
    use HasUuid;

    protected $table = 'pod_media';

    protected $fillable = ['company_uuid', 'client_media_id', 'sha256', 'kind', 'mime', 'size', 'disk', 'path', 'user_uuid'];

    protected $casts = ['size' => 'integer'];

    protected $hidden = ['disk', 'path'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Uploaded evidence cannot be changed.'));
    }
}
