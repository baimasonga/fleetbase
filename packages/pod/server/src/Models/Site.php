<?php

namespace Fleetbase\Pod\Models;

use Fleetbase\Models\Model;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;

/**
 * A place that holds stock: central warehouse, district store, distribution
 * point, or a vehicle used as a mobile store on door-to-door routes.
 */
class Site extends Model
{
    use HasUuid;
    use HasPublicId;

    public const TYPES = ['central_warehouse', 'district_store', 'distribution_point', 'vehicle'];

    protected $table = 'pod_sites';

    protected $publicIdType = 'site';

    protected $fillable = ['company_uuid', 'name', 'type', 'country', 'admin_1', 'admin_2', 'admin_3', 'admin_4', 'village', 'latitude', 'longitude', 'geofence_radius_m', 'place_uuid', 'vehicle_uuid', 'active', 'meta'];

    protected $casts = [
        'latitude'          => 'float',
        'longitude'         => 'float',
        'geofence_radius_m' => 'integer',
        'active'            => 'boolean',
        'meta'              => 'array',
    ];
}
