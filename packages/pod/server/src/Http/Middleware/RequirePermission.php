<?php

namespace Fleetbase\Pod\Http\Middleware;

use Fleetbase\Pod\Auth\Gate;

/**
 * Route guard: `pod.can:resolve conflict` or, for alternatives,
 * `pod.can:depart trip|depart own-trip`. Names without the "pod " prefix.
 */
class RequirePermission
{
    public function __construct(private Gate $gate)
    {
    }

    public function handle($request, \Closure $next, string $permissions)
    {
        $names = array_map(fn ($p) => 'pod ' . trim($p), explode('|', $permissions));
        foreach ($names as $name) {
            if ($this->gate->allows($name)) {
                return $next($request);
            }
        }

        return response()->json([
            'error'      => 'forbidden',
            'permission' => $names[0],
            'errors'     => ['Your account is not allowed to do this (' . $names[0] . '). Ask an administrator to give you the right Invendis role.'],
        ], 403);
    }
}
