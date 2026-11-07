<?php

namespace Fleetbase\Pod\Models;

use Fleetbase\Pod\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * One entry in the append-only stock ledger.
 */
class StockMovement extends Model
{
    use HasUuid;

    public const UPDATED_AT = null;

    protected $table = 'pod_stock_movements';

    protected $fillable = ['company_uuid', 'programme_uuid', 'holder_type', 'holder_uuid', 'sku', 'lot', 'expiry', 'quantity', 'reason', 'reference_type', 'reference_uuid', 'user_uuid', 'note'];

    protected $casts = ['quantity' => 'float', 'expiry' => 'date:Y-m-d'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Stock movements cannot be changed. Record a correcting movement instead.'));
        static::deleting(fn () => throw new \LogicException('Stock movements cannot be deleted. Record a correcting movement instead.'));
    }
}
