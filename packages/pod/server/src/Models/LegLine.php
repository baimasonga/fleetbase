<?php

namespace Fleetbase\Pod\Models;

use Illuminate\Database\Eloquent\Model;

class LegLine extends Model
{
    protected $table = 'pod_leg_lines';

    protected $fillable = ['leg_uuid', 'sku', 'lot', 'expiry', 'loaded_quantity', 'received_good', 'received_damaged', 'condition_note'];

    protected $casts = [
        'loaded_quantity'  => 'float',
        'received_good'    => 'float',
        'received_damaged' => 'float',
        'expiry'           => 'date:Y-m-d',
    ];
}
