<?php

namespace Fleetbase\Pod\Support\Access;

/**
 * A set of Fleetbase permission names ("pod <action> <resource>") and the
 * check Fleetbase itself applies: a permission is held if the exact name,
 * "pod * <resource>" or "pod *" is held.
 *
 * Also expands a permission schema's roles into permission names the way
 * `fleetbase:create-permissions` does, so the roles can be tested and shown
 * without a database.
 */
final class PermissionSet
{
    /** @var array<string, true> */
    private array $names = [];

    /**
     * @param iterable<string> $names
     */
    public function __construct(iterable $names)
    {
        foreach ($names as $name) {
            $this->names[$name] = true;
        }
    }

    /**
     * The permissions a person with these roles holds. "Administrator" is
     * Fleetbase's organisation administrator role: everything.
     *
     * @param object   $schema an Auth schema (name, policies, roles)
     * @param string[] $roles
     */
    public static function forRoles(object $schema, array $roles): self
    {
        $service  = $schema->name;
        $policies = [];
        foreach ($schema->policies ?? [] as $policy) {
            $policies[$policy['name']] = $policy['permissions'] ?? [];
        }

        $names = [];
        foreach ($roles as $roleName) {
            if ($roleName === 'Administrator') {
                $names[] = $service . ' *';
                continue;
            }
            foreach ($schema->roles ?? [] as $role) {
                if ($role['name'] !== $roleName) {
                    continue;
                }
                foreach (array_merge(...array_map(fn ($p) => $policies[$p] ?? [], $role['policies'] ?? [])) as $permission) {
                    $names[] = self::qualify($service, $permission);
                }
                foreach ($role['permissions'] ?? [] as $permission) {
                    $names[] = self::qualify($service, $permission);
                }
            }
        }

        return new self($names);
    }

    /**
     * Every permission name the schema defines (as Fleetbase creates them).
     *
     * @return string[]
     */
    public static function defined(object $schema): array
    {
        $service = $schema->name;
        $names   = [$service . ' *', $service . ' see extension'];
        foreach ($schema->resources ?? [] as $resource) {
            $names[] = "{$service} * {$resource['name']}";
            foreach (array_merge(['create', 'update', 'delete', 'view', 'list', 'see'], $resource['actions'] ?? []) as $action) {
                $names[] = "{$service} {$action} {$resource['name']}";
            }
        }

        return array_values(array_unique($names));
    }

    public function allows(string $permission): bool
    {
        $parts = explode(' ', $permission);
        if (count($parts) !== 3) {
            return isset($this->names[$permission]) || isset($this->names[$parts[0] . ' *']);
        }
        [$service, , $resource] = $parts;

        return isset($this->names[$permission]) || isset($this->names["{$service} * {$resource}"]) || isset($this->names["{$service} *"]);
    }

    /**
     * @return string[]
     */
    public function names(): array
    {
        return array_keys($this->names);
    }

    private static function qualify(string $service, string $permission): string
    {
        return count(explode(' ', $permission)) === 3 ? $permission : $service . ' ' . $permission;
    }
}
