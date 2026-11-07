<?php

namespace Fleetbase\Pod\Auth;

use Fleetbase\Support\Auth;

/**
 * Fleetbase's own check: the person's roles, policies and direct permissions
 * in IAM, with "pod * <resource>" and "pod *" wildcards. Platform
 * administrators may do everything, as elsewhere in Fleetbase.
 */
class FleetbaseGate implements Gate
{
    /** @var array<string, bool> */
    private array $cache = [];

    public function allows(string $permission): bool
    {
        if (array_key_exists($permission, $this->cache)) {
            return $this->cache[$permission];
        }

        $user = Auth::getUserFromSession();
        if (!$user) {
            return $this->cache[$permission] = false;
        }

        return $this->cache[$permission] = $user->isAdmin() || Auth::can($permission);
    }
}
