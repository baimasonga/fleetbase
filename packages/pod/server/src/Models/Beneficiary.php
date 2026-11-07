<?php

namespace Fleetbase\Pod\Models;

use Fleetbase\Models\Model;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;

class Beneficiary extends Model
{
    use HasUuid;
    use HasPublicId;

    protected $table = 'pod_beneficiaries';

    protected $publicIdType = 'beneficiary';

    protected $fillable = [
        'company_uuid', 'programme_uuid', 'type', 'farmer_ref', 'name', 'sex', 'birth_year',
        'phone', 'phone_extra', 'id_type', 'id_last4', 'id_hash',
        'admin_1', 'admin_2', 'admin_3', 'admin_4', 'village', 'group_name',
        'household_size', 'farm_size', 'crop', 'livestock', 'notes', 'latitude', 'longitude',
        'household_members', 'proxy', 'photo_file_uuid', 'card_issue', 'trained_restricted_products',
        'status', 'verification_status', 'verification_method', 'verified_at', 'verified_by_uuid', 'consent_at',
        'source', 'source_file_uuid', 'import_batch_uuid', 'meta',
    ];

    protected $casts = [
        'birth_year'                  => 'integer',
        'household_size'              => 'integer',
        'card_issue'                  => 'integer',
        'latitude'                    => 'float',
        'longitude'                   => 'float',
        'household_members'           => 'array',
        'livestock'                   => 'array',
        'proxy'                       => 'array',
        'source'                      => 'array',
        'meta'                        => 'array',
        'verified_at'                 => 'datetime',
        'consent_at'                  => 'datetime',
        'trained_restricted_products' => 'date',
    ];

    /**
     * The duplicate-check hash is internal; never send it to clients.
     */
    protected $hidden = ['id_hash'];

    public function programme()
    {
        return $this->belongsTo(Programme::class, 'programme_uuid', 'uuid');
    }

    public function entitlements()
    {
        return $this->hasMany(Entitlement::class, 'beneficiary_uuid', 'uuid');
    }

    public function cardEvents()
    {
        return $this->hasMany(CardEvent::class, 'beneficiary_uuid', 'uuid')->orderBy('id');
    }

    public function isVerified(): bool
    {
        return $this->verification_status === 'verified';
    }

    /**
     * The fields EntitlementCalculator reads.
     */
    public function toEntitlementInput(): array
    {
        return [
            'type'                        => $this->type,
            'sex'                         => $this->sex,
            'crop'                        => $this->crop,
            'group_name'                  => $this->group_name,
            'admin_1'                     => $this->admin_1,
            'admin_2'                     => $this->admin_2,
            'admin_3'                     => $this->admin_3,
            'admin_4'                     => $this->admin_4,
            'household_size'              => $this->household_size,
            'farm_size'                   => $this->farm_size,
            'livestock'                   => $this->livestock ?? [],
            'trained_restricted_products' => $this->trained_restricted_products !== null,
        ];
    }

    /**
     * The fields the duplicate detector compares, in its shape.
     */
    public function toDuplicateCandidate(): array
    {
        return [
            'data' => [
                'name'       => $this->name,
                'sex'        => $this->sex,
                'birth_year' => $this->birth_year,
                'village'    => $this->village,
                'phone'      => $this->phone,
                'id_hash'    => $this->id_hash,
            ],
            'place' => $this->admin_4 ?? $this->admin_3 ?? $this->admin_2 ?? $this->admin_1,
        ];
    }
}
