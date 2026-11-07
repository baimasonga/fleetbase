<?php

namespace Fleetbase\Pod\Models;

use Fleetbase\Pod\Support\AdminAreas\AdminArea as AdminAreaValue;
use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * An official administrative area. Reference data shared by all companies.
 */
class AdminArea extends Model
{
    use HasUuid;

    protected $table = 'pod_admin_areas';

    protected $fillable = ['country', 'level', 'level_label', 'pcode', 'name', 'source_name', 'parent_pcode', 'center_lat', 'center_lon', 'review_flags'];

    protected $casts = [
        'level'        => 'integer',
        'center_lat'   => 'float',
        'center_lon'   => 'float',
        'review_flags' => 'array',
    ];

    public function toValue(): AdminAreaValue
    {
        return new AdminAreaValue(
            country: $this->country,
            level: $this->level,
            pcode: $this->pcode,
            name: $this->name,
            parentPcode: $this->parent_pcode,
            sourceName: $this->source_name,
            lat: $this->center_lat,
            lon: $this->center_lon,
            reviewFlags: $this->review_flags ?? [],
            levelLabel: $this->level_label,
        );
    }
}
