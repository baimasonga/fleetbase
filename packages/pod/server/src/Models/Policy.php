<?php

namespace Fleetbase\Pod\Models;

use Fleetbase\Models\Model;
use Fleetbase\Pod\Support\Policy\RequirementTree;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;

class Policy extends Model
{
    use HasUuid;
    use HasPublicId;

    protected $table = 'pod_policies';

    protected $publicIdType = 'pod_policy';

    protected $fillable = [
        'company_uuid', 'name', 'scope_type', 'scope_uuid', 'priority', 'requirements',
        'geofence_radius_m', 'time_window_grace_min', 'offline_mode', 'offline_fallback',
        'max_attempts', 'max_offline_hours', 'max_media_delay_hours', 'active',
    ];

    protected $casts = [
        'priority'         => 'integer',
        'requirements'     => 'array',
        'offline_fallback' => 'array',
        'active'           => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (Policy $policy) {
            RequirementTree::assertValid($policy->requirements ?? []);
            if ($policy->offline_fallback) {
                RequirementTree::assertValid($policy->offline_fallback);
            }
        });
    }

    /**
     * In the shape PolicyResolver takes.
     */
    public function toCandidate(): array
    {
        return [
            'id'           => $this->public_id,
            'priority'     => $this->priority,
            'scope_type'   => $this->scope_type,
            'scope_id'     => $this->scope_uuid,
            'requirements' => $this->requirements,
            'active'       => $this->active,
        ];
    }
}
