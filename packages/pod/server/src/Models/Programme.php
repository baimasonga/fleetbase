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

    public function items()
    {
        return $this->hasMany(ProgrammeItem::class, 'programme_uuid', 'uuid')->orderBy('sort')->orderBy('id');
    }

    public function entitlements()
    {
        return $this->hasMany(Entitlement::class, 'programme_uuid', 'uuid');
    }

    /**
     * Entitlement rules (see EntitlementCalculator for the format).
     */
    public function entitlementRules(): array
    {
        return $this->rules['entitlements'] ?? [];
    }

    /**
     * Whether entitlements may be created before field verification, with
     * verification done at the first handover (AGRI-INPUTS.md §7.1 step 4).
     */
    public function allowsUnverifiedEntitlements(): bool
    {
        return (bool) ($this->rules['verify_on_first_handover'] ?? false);
    }

    public function defaultDeliveryModel(): string
    {
        return in_array($this->rules['delivery_model'] ?? null, ['distribution_point', 'door_to_door'], true)
            ? $this->rules['delivery_model']
            : 'distribution_point';
    }

    public function importBatches()
    {
        return $this->hasMany(ImportBatch::class, 'programme_uuid', 'uuid');
    }
}
