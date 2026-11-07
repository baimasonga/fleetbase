<?php

namespace Fleetbase\Pod\Models;

use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * One handover (or failed attempt) to one farmer. Sealed when recorded; only
 * voiding (with a reason) can change it afterwards.
 */
class Handover extends Model
{
    use HasUuid;
    use HasPublicId;

    protected $table = 'pod_handovers';

    protected $publicIdType = 'handover';

    protected $fillable = [
        'company_uuid', 'programme_uuid', 'event_uuid', 'beneficiary_uuid', 'client_handover_id', 'outcome', 'failure_reason',
        'received_by', 'recipient_name', 'identity_level', 'identity_checks', 'lines', 'evidence', 'flags',
        'latitude', 'longitude', 'distance_m', 'captured_at', 'attempt_number', 'officer_uuid', 'status', 'void_reason', 'seal_hash',
    ];

    protected $casts = [
        'identity_checks' => 'array',
        'lines'           => 'array',
        'evidence'        => 'array',
        'flags'           => 'array',
        'latitude'        => 'float',
        'longitude'       => 'float',
        'distance_m'      => 'integer',
        'attempt_number'  => 'integer',
        'captured_at'     => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (Handover $handover) {
            // A sealed handover may only be voided.
            $allowed = ['status', 'void_reason', 'updated_at'];
            if (array_diff(array_keys($handover->getDirty()), $allowed) || $handover->getOriginal('status') !== 'sealed') {
                throw new \LogicException('A sealed handover cannot be changed. Void it with a reason instead.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Handovers cannot be deleted. Void them with a reason instead.'));
    }

    public function beneficiary()
    {
        return $this->belongsTo(Beneficiary::class, 'beneficiary_uuid', 'uuid');
    }
}
