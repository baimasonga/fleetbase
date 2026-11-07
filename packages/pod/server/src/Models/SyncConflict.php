<?php

namespace Fleetbase\Pod\Models;

use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class SyncConflict extends Model
{
    use HasUuid;

    protected $table = 'pod_sync_conflicts';

    protected $fillable = ['company_uuid', 'event_uuid', 'client_handover_id', 'kind', 'payload', 'error', 'message', 'details', 'status', 'resolution_notes', 'user_uuid', 'resolved_by_uuid', 'resolved_at'];

    protected $casts = ['payload' => 'array', 'details' => 'array', 'resolved_at' => 'datetime'];
}
