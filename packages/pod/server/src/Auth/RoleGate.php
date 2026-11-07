<?php

namespace Fleetbase\Pod\Auth;

use Fleetbase\Pod\Auth\Schemas\Pod;
use Fleetbase\Pod\Support\Access\PermissionSet;

/**
 * Roles given per user id, expanded from the PoD schema. For tests and
 * demonstrations without Fleetbase IAM; production uses FleetbaseGate.
 */
class RoleGate implements Gate
{
    /**
     * @param array<string, string[]> $rolesByUser user uuid => role names, or permission names given directly ("pod sync field")
     */
    public function __construct(private array $rolesByUser)
    {
    }

    public function allows(string $permission): bool
    {
        $given  = $this->rolesByUser[session('user')] ?? [];
        $direct = array_filter($given, fn ($g) => str_starts_with($g, 'pod '));
        $roles  = PermissionSet::forRoles(new Pod(), array_diff($given, $direct));

        return $roles->allows($permission) || (new PermissionSet($direct))->allows($permission);
    }
}
