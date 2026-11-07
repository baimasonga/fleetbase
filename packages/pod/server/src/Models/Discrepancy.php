<?php

namespace Fleetbase\Pod\Models;

use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * Stock that is missing, damaged or more than expected, on a trip or at a
 * distribution event, with the reason and who is liable. An audit record:
 * it can be explained and resolved, never deleted.
 */
class Discrepancy extends Model
{
    use HasUuid;
    use HasPublicId;

    public const REASONS = ['short_delivered', 'damaged', 'expired', 'wet', 'torn_bag', 'spill_leak', 'counterfeit_suspected', 'theft', 'seal_broken', 'excess', 'count_error', 'other'];

    public const LIABLE = ['transporter', 'store', 'officer', 'unknown'];

    public const RESOLUTIONS = ['written_off', 'replaced', 'recovered', 'investigating'];

    protected $table = 'pod_discrepancies';

    protected $publicIdType = 'discrepancy';

    protected $fillable = ['company_uuid', 'programme_uuid', 'subject_type', 'subject_uuid', 'sku', 'lot', 'quantity', 'reason', 'liable_party', 'notes', 'evidence', 'status', 'resolution', 'created_by_uuid', 'resolved_by_uuid', 'resolved_at'];

    protected $casts = ['quantity' => 'float', 'evidence' => 'array', 'resolved_at' => 'datetime'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Discrepancies cannot be deleted. Resolve them with an explanation instead.'));
    }
}
