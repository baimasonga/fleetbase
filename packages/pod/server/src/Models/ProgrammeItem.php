<?php

namespace Fleetbase\Pod\Models;

use Fleetbase\Pod\Support\Entitlements\ProgrammeItem as ItemValue;
use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class ProgrammeItem extends Model
{
    use HasUuid;

    protected $table = 'pod_programme_items';

    protected $fillable = ['company_uuid', 'programme_uuid', 'sku', 'name', 'category', 'unit', 'pack_size', 'pack_unit', 'restricted', 'cold_chain', 'max_per_beneficiary', 'sort'];

    protected $casts = [
        'pack_size'           => 'float',
        'max_per_beneficiary' => 'float',
        'restricted'          => 'boolean',
        'cold_chain'          => 'boolean',
        'sort'                => 'integer',
    ];

    public function toValue(): ItemValue
    {
        return ItemValue::fromArray($this->only(['sku', 'name', 'category', 'unit', 'pack_size', 'pack_unit', 'restricted', 'cold_chain', 'max_per_beneficiary']));
    }
}
