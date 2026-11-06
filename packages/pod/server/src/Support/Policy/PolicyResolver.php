<?php

namespace Fleetbase\Pod\Support\Policy;

/**
 * Picks the PoD policy that applies to an order or stop (DESIGN.md §6.1).
 *
 * Candidates are matched by scope. A policy with no scope is the company
 * default. Among the policies that match, the highest priority wins; on a tie
 * the more specific scope wins (stop/order > order config > customer >
 * programme > service area > zone > default).
 *
 * The legacy Fleet-Ops `pod_required` / `pod_method` on the order or stop is
 * always added on top, so turning on a policy never weakens an existing rule.
 */
final class PolicyResolver
{
    private const SPECIFICITY = [
        'waypoint'     => 70,
        'order'        => 70,
        'order_config' => 60,
        'customer'     => 50,
        'programme'    => 40,
        'service_area' => 30,
        'zone'         => 20,
        null           => 0,
    ];

    /**
     * Fleet-Ops pod_method values and the requirement each means.
     */
    public const LEGACY_METHODS = [
        'signature' => ['type' => 'signature'],
        'photo'     => ['type' => 'photo'],
        'scan'      => ['type' => 'qr_scan'],
    ];

    /**
     * @param list<array{id?: string, priority?: int, scope_type?: ?string, scope_id?: ?string, requirements: array, active?: bool}> $candidates
     * @param array<string, string|list<string>>                                                                                  $context scope type => id (or ids) that apply
     *
     * @return array{policy: ?array, requirements: ?array} requirements is null when no proof is required
     */
    public function resolve(array $candidates, array $context, bool $legacyRequired = false, ?string $legacyMethod = null): array
    {
        $best = null;
        foreach ($candidates as $candidate) {
            if (($candidate['active'] ?? true) === false || !$this->matches($candidate, $context)) {
                continue;
            }
            if ($best === null || $this->compare($candidate, $best) > 0) {
                $best = $candidate;
            }
        }

        $requirements = $best['requirements'] ?? null;

        if ($legacyRequired) {
            $legacy       = self::LEGACY_METHODS[$legacyMethod ?? ''] ?? ['any' => array_values(self::LEGACY_METHODS)];
            $requirements = $requirements ? RequirementTree::allOf($legacy, $requirements) : $legacy;
        }

        return ['policy' => $best, 'requirements' => $requirements];
    }

    private function matches(array $candidate, array $context): bool
    {
        $type = $candidate['scope_type'] ?? null;
        if ($type === null) {
            return true;
        }
        if (!array_key_exists($type, $context)) {
            return false;
        }

        $ids = (array) $context[$type];

        return in_array($candidate['scope_id'] ?? null, $ids, true);
    }

    private function compare(array $a, array $b): int
    {
        $byPriority = ($a['priority'] ?? 0) <=> ($b['priority'] ?? 0);
        if ($byPriority !== 0) {
            return $byPriority;
        }

        return (self::SPECIFICITY[$a['scope_type'] ?? null] ?? 0) <=> (self::SPECIFICITY[$b['scope_type'] ?? null] ?? 0);
    }
}
