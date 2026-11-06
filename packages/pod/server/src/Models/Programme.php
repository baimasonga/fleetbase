<?php

namespace Fleetbase\Pod\Models;

use Fleetbase\Models\Model;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;

class Programme extends Model
{
    use HasUuid;
    use HasPublicId;

    protected $table = 'pod_programmes';

    protected $publicIdType = 'programme';

    protected $fillable = ['company_uuid', 'name', 'operator_type', 'funder', 'season', 'country', 'status', 'planting_windows', 'rules', 'meta', 'created_by_uuid'];

    protected $casts = [
        'planting_windows' => 'array',
        'rules'            => 'array',
        'meta'             => 'array',
    ];

    public const OPERATOR_TYPES = ['ngo', 'project', 'government'];

    public const STATUSES = ['draft', 'active', 'closed'];

    public function beneficiaries()
    {
        return $this->hasMany(Beneficiary::class, 'programme_uuid', 'uuid');
    }

    public function importBatches()
    {
        return $this->hasMany(ImportBatch::class, 'programme_uuid', 'uuid');
    }
}
