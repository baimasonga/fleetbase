<?php

namespace Fleetbase\Pod\Models;

use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class Entitlement extends Model
{
    use HasUuid;

    protected $table = 'pod_entitlements';

    public const OPEN                = 'open';
    public const PARTIALLY_COLLECTED = 'partially_collected';
    public const COLLECTED           = 'collected';
    public const REASSIGNED          = 'reassigned';
    public const EXPIRED             = 'expired';
    public const CANCELLED           = 'cancelled';

    protected $fillable = [
        'company_uuid', 'programme_uuid', 'beneficiary_uuid', 'sku', 'quantity', 'unit', 'collected_quantity',
        'status', 'delivery_model', 'distribution_event_uuid', 'route_order_uuid', 'rule_index', 'cancel_reason',
    ];

    protected $casts = [
        'quantity'           => 'float',
        'collected_quantity' => 'float',
        'rule_index'         => 'integer',
    ];

    public function beneficiary()
    {
        return $this->belongsTo(Beneficiary::class, 'beneficiary_uuid', 'uuid');
    }

    /**
     * Nothing has been handed over yet, so the entitlement can still change.
     */
    public function isUntouched(): bool
    {
        return $this->status === self::OPEN && $this->collected_quantity <= 0;
    }
}
