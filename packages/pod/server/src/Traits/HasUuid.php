<?php

namespace Fleetbase\Pod\Traits;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * UUIDs for reference and event models without Fleetbase's soft-delete support.
 * Keep their auto-incrementing integer primary key; generate only the uuid.
 */
trait HasUuid
{
    use HasUuids;

    public function uniqueIds(): array
    {
        return ['uuid'];
    }
}
