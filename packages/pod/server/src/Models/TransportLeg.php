<?php

namespace Fleetbase\Pod\Models;

use Fleetbase\Models\Model;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;

/**
 * A truck trip between two sites.
 */
class TransportLeg extends Model
{
    use HasUuid;
    use HasPublicId;

    public const DRAFT      = 'draft';
    public const LOADED     = 'loaded';
    public const IN_TRANSIT = 'in_transit';
    public const ARRIVED    = 'arrived';
    public const RECEIVED   = 'received';
    public const CLOSED     = 'closed';
    public const CANCELLED  = 'cancelled';

    protected $table = 'pod_transport_legs';

    protected $publicIdType = 'leg';

    protected $fillable = [
        'company_uuid', 'programme_uuid', 'origin_site_uuid', 'destination_site_uuid', 'order_uuid', 'status',
        'transporter_kind', 'transporter_name', 'transporter_phone', 'transporter_user_uuid', 'vendor_uuid', 'driver_uuid', 'vehicle_uuid', 'vehicle_plate', 'waybill_no',
        'seals_loaded', 'seals_received', 'seal_status', 'restricted_segregated', 'expected_duration_min',
        'loaded_at', 'loaded_by_uuid', 'departed_at', 'arrived_at', 'received_at', 'received_by_uuid', 'closed_at', 'closed_by_uuid',
        'flags', 'notes', 'seal_hash',
    ];

    protected $casts = [
        'seals_loaded'          => 'array',
        'seals_received'        => 'array',
        'flags'                 => 'array',
        'restricted_segregated' => 'boolean',
        'expected_duration_min' => 'integer',
        'loaded_at'             => 'datetime',
        'departed_at'           => 'datetime',
        'arrived_at'            => 'datetime',
        'received_at'           => 'datetime',
        'closed_at'             => 'datetime',
    ];

    public function lines()
    {
        return $this->hasMany(LegLine::class, 'leg_uuid', 'uuid')->orderBy('sku')->orderBy('lot');
    }

    public function origin()
    {
        return $this->belongsTo(Site::class, 'origin_site_uuid', 'uuid');
    }

    public function destination()
    {
        return $this->belongsTo(Site::class, 'destination_site_uuid', 'uuid');
    }

    public function discrepancies()
    {
        return $this->hasMany(Discrepancy::class, 'subject_uuid', 'uuid')->where('subject_type', 'leg');
    }

    public function programme()
    {
        return $this->belongsTo(Programme::class, 'programme_uuid', 'uuid');
    }
}
