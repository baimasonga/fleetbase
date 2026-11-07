<?php

namespace Fleetbase\Pod\Models;

use Fleetbase\Models\Model;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;

/**
 * A distribution day at a distribution point (kind "point"), or a
 * door-to-door route (kind "route").
 */
class DistributionEvent extends Model
{
    use HasUuid;
    use HasPublicId;

    public const PLANNED   = 'planned';
    public const OPEN      = 'open';
    public const CLOSED    = 'closed';
    public const CANCELLED = 'cancelled';

    protected $table = 'pod_distribution_events';

    protected $publicIdType = 'event';

    protected $fillable = [
        'company_uuid', 'programme_uuid', 'kind', 'site_uuid', 'name', 'scheduled_date', 'status',
        'opened_at', 'opened_by_uuid', 'open_client_id', 'closed_at', 'closed_by_uuid', 'close_client_id',
        'opening_stock', 'closing_stock', 'witnesses', 'reconciliation', 'totals', 'seal_hash',
    ];

    protected $casts = [
        'scheduled_date' => 'date:Y-m-d',
        'opened_at'      => 'datetime',
        'closed_at'      => 'datetime',
        'opening_stock'  => 'array',
        'closing_stock'  => 'array',
        'witnesses'      => 'array',
        'reconciliation' => 'array',
        'totals'         => 'array',
    ];

    public function site()
    {
        return $this->belongsTo(Site::class, 'site_uuid', 'uuid');
    }

    public function programme()
    {
        return $this->belongsTo(Programme::class, 'programme_uuid', 'uuid');
    }

    public function handovers()
    {
        return $this->hasMany(Handover::class, 'event_uuid', 'uuid');
    }

    public function entitlements()
    {
        return $this->hasMany(Entitlement::class, 'distribution_event_uuid', 'uuid');
    }

    public function discrepancies()
    {
        return $this->hasMany(Discrepancy::class, 'subject_uuid', 'uuid')->where('subject_type', 'event');
    }
}
