<?php

namespace Fleetbase\Pod\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An append-only log of programme card actions.
 */
class CardEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'pod_card_events';

    protected $fillable = ['company_uuid', 'programme_uuid', 'beneficiary_uuid', 'issue_number', 'action', 'reason', 'print_batch', 'user_uuid'];

    protected $casts = ['issue_number' => 'integer'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Card events cannot be changed.'));
        static::deleting(fn () => throw new \LogicException('Card events cannot be deleted.'));
    }
}
