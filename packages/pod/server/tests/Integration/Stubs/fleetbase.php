<?php

/*
 * Minimal stand-ins for the Fleetbase core and Fleet-Ops classes the PoD
 * package uses, so its Laravel layer can be tested without installing
 * Fleetbase. They are loaded only when the real classes are missing, and
 * copy the behaviour the package relies on (soft deletes, uuid and public id
 * on create, Fleet-Ops activity flags), nothing more.
 */

namespace Fleetbase\Traits {
    use Illuminate\Support\Str;

    if (!trait_exists(HasUuid::class, false)) {
        trait HasUuid
        {
            public static function bootHasUuid(): void
            {
                static::creating(function ($model) {
                    if (empty($model->uuid)) {
                        $model->uuid = static::generateUuid();
                    }
                });
            }

            public static function generateUuid($column = 'uuid')
            {
                $uuid = (string) Str::uuid();
                // Match core-api's soft-delete assumption so plain Eloquent
                // models cannot silently pass here and fail during deployment.
                if ((new static())->where($column, $uuid)->withTrashed()->exists()) {
                    return static::generateUuid($column);
                }

                return $uuid;
            }
        }
    }

    if (!trait_exists(HasPublicId::class, false)) {
        trait HasPublicId
        {
            public static function bootHasPublicId(): void
            {
                static::creating(function ($model) {
                    if (empty($model->public_id)) {
                        $model->public_id = $model->publicIdType . '_' . Str::lower(Str::random(7));
                    }
                });
            }
        }
    }
}

namespace Fleetbase\Models {
    use Illuminate\Database\Eloquent\SoftDeletes;

    if (!class_exists(Model::class, false)) {
        class Model extends \Illuminate\Database\Eloquent\Model
        {
            use SoftDeletes;
        }
    }
}

namespace Fleetbase\FleetOps\Flow {
    if (!class_exists(Activity::class, false)) {
        class Activity
        {
            public ?string $code;

            public function __construct(private array $attributes = [], array $flow = [])
            {
                $this->code = $attributes['code'] ?? null;
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->attributes[$key] ?? $default;
            }

            public function completesOrder(): bool
            {
                return (bool) ($this->attributes['complete'] ?? false);
            }
        }
    }
}

namespace Fleetbase\FleetOps\Support {
    if (!class_exists(Utils::class, false)) {
        class Utils
        {
            public static function isActivity($activity): bool
            {
                return $activity instanceof \Fleetbase\FleetOps\Flow\Activity && !empty($activity->code);
            }
        }
    }
}

namespace Fleetbase\FleetOps\Models {
    if (!class_exists(Order::class, false)) {
        class Order extends \Illuminate\Database\Eloquent\Model
        {
            protected $table   = 'orders';
            protected $guarded = [];
            protected $casts   = ['pod_required' => 'boolean'];

            public function getConfigFlow(): array
            {
                return [];
            }
        }
    }

    if (!class_exists(Proof::class, false)) {
        class Proof extends \Illuminate\Database\Eloquent\Model
        {
            protected $table   = 'proofs';
            protected $guarded = [];
        }
    }
}

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1 {
    if (!class_exists(OrderController::class, false)) {
        class OrderController extends \Illuminate\Routing\Controller
        {
            public function updateActivity(string $id)
            {
                return response()->json(['updated' => $id]);
            }
        }
    }
}
