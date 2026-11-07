<?php

namespace Fleetbase\Pod\Support\Policy;

/**
 * Evaluates a PoD policy's requirement tree against the evidence collected
 * (DESIGN.md §5, AGRI-INPUTS.md §8).
 *
 * A tree is either a group or a leaf:
 *
 *   {"all": [ ...nodes ]}            every node must be satisfied
 *   {"any": [ ...nodes ]}            at least one node must be satisfied
 *   {"type": "photo", "min": 2}      a leaf: evidence of this type
 *
 * Evidence is a list of arrays with at least a "type" key, e.g.
 *   ['type' => 'signature', 'name' => 'Aminata Kamara']
 *   ['type' => 'beneficiary_id', 'level' => 'L2']
 *
 * Most leaves just count evidence of their type (default min 1). Leaves with
 * extra rules (an identity level, a name on the signature, coverage of every
 * line) use a checker registered for the type.
 */
final class RequirementTree
{
    /** @var array<string, callable(array $leaf, list<array> $evidence, array $context): bool> */
    private array $checkers = [];

    public function __construct()
    {
        $this->registerDefaults();
    }

    /**
     * Add or replace the rule for a leaf type.
     *
     * @param callable(array $leaf, list<array> $evidenceOfType, array $context): bool $checker
     */
    public function register(string $type, callable $checker): void
    {
        $this->checkers[$type] = $checker;
    }

    /**
     * @param array       $tree
     * @param list<array> $evidence
     * @param array       $context  extra facts the checkers may need (e.g. lines on the stop)
     */
    public function evaluate(array $tree, array $evidence, array $context = []): EvaluationResult
    {
        self::assertValid($tree);

        $byType = [];
        foreach ($evidence as $item) {
            if (isset($item['type'])) {
                $byType[$item['type']][] = $item;
            }
        }

        $missing   = [];
        $satisfied = $this->node($tree, $byType, $context, $missing);

        return new EvaluationResult($satisfied, $satisfied ? [] : $missing);
    }

    /**
     * Combine trees so that all of them must be met (used to add a legacy
     * Fleet-Ops pod_method, or programme rules, to a policy).
     */
    public static function allOf(array ...$trees): array
    {
        $nodes = [];
        foreach ($trees as $tree) {
            if (!$tree) {
                continue;
            }
            // Flatten nested "all" groups for a cleaner tree.
            if (isset($tree['all']) && count($tree) === 1) {
                array_push($nodes, ...$tree['all']);
            } else {
                $nodes[] = $tree;
            }
        }

        return count($nodes) === 1 ? $nodes[0] : ['all' => $nodes];
    }

    /**
     * @throws \InvalidArgumentException when the tree is malformed
     */
    public static function assertValid(array $tree, string $path = '$'): void
    {
        $groups = array_intersect(array_keys($tree), ['all', 'any']);

        if ($groups) {
            if (count($tree) !== 1) {
                throw new \InvalidArgumentException("{$path}: a group must contain only \"all\" or \"any\".");
            }
            $key = reset($groups);
            if (!is_array($tree[$key]) || !array_is_list($tree[$key]) || $tree[$key] === []) {
                throw new \InvalidArgumentException("{$path}.{$key}: must be a non-empty list.");
            }
            foreach ($tree[$key] as $i => $child) {
                if (!is_array($child)) {
                    throw new \InvalidArgumentException("{$path}.{$key}[{$i}]: must be an object.");
                }
                self::assertValid($child, "{$path}.{$key}[{$i}]");
            }

            return;
        }

        if (!isset($tree['type']) || !is_string($tree['type']) || $tree['type'] === '') {
            throw new \InvalidArgumentException("{$path}: a requirement needs a \"type\".");
        }
        if (isset($tree['min']) && (!is_int($tree['min']) || $tree['min'] < 1)) {
            throw new \InvalidArgumentException("{$path}: \"min\" must be a whole number of at least 1.");
        }
    }

    private function node(array $node, array $byType, array $context, array &$missing): bool
    {
        if (isset($node['all'])) {
            $ok = true;
            foreach ($node['all'] as $child) {
                // Keep going so every missing requirement is reported, not just the first.
                $ok = $this->node($child, $byType, $context, $missing) && $ok;
            }

            return $ok;
        }

        if (isset($node['any'])) {
            $childMissing = [];
            foreach ($node['any'] as $child) {
                $ignored = [];
                if ($this->node($child, $byType, $context, $ignored)) {
                    return true;
                }
                $childMissing[] = $child;
            }
            $missing[] = ['any' => $childMissing];

            return false;
        }

        $type    = $node['type'];
        $items   = $byType[$type] ?? [];
        $checker = $this->checkers[$type] ?? $this->checkers['*'];

        if ($checker($node, $items, $context)) {
            return true;
        }
        $missing[] = $node;

        return false;
    }

    private function registerDefaults(): void
    {
        // Default: count the evidence of the type.
        $this->checkers['*'] = fn (array $leaf, array $items) => count($items) >= ($leaf['min'] ?? 1);

        // A signature, optionally with the signer's name.
        $this->checkers['signature'] = function (array $leaf, array $items) {
            if (!empty($leaf['require_name'])) {
                $items = array_filter($items, fn ($item) => trim((string) ($item['name'] ?? '')) !== '');
            }

            return count($items) >= ($leaf['min'] ?? 1);
        };

        // Who received: identity level L1 < L2 < L3 (AGRI-INPUTS.md §7.2).
        $this->checkers['beneficiary_id'] = function (array $leaf, array $items) {
            $min = IdentityLevels::rank($leaf['min_level'] ?? 'L1');
            foreach ($items as $item) {
                if (IdentityLevels::rank($item['level'] ?? null) >= $min) {
                    if (empty($leaf['methods']) || in_array($item['method'] ?? null, $leaf['methods'], true)) {
                        return true;
                    }
                }
            }

            return false;
        };

        // Lot scans: with coverage "all_lines", every line on the handover needs a scanned lot.
        $this->checkers['lot_scan'] = function (array $leaf, array $items, array $context) {
            if (($leaf['coverage'] ?? null) !== 'all_lines') {
                return count($items) >= ($leaf['min'] ?? 1);
            }
            $lines = $context['lines'] ?? [];
            if (!$lines) {
                return count($items) >= 1;
            }
            $scanned = array_flip(array_map(fn ($item) => (string) ($item['line'] ?? ''), $items));
            foreach ($lines as $line) {
                if (!isset($scanned[(string) ($line['id'] ?? '')])) {
                    return false;
                }
            }

            return true;
        };

        // Quantities confirmed for every line; with match "entitlement", no line may exceed it.
        $this->checkers['line_quantities'] = function (array $leaf, array $items, array $context) {
            $lines = $context['lines'] ?? [];
            if (!$items) {
                return false;
            }
            $confirmed = [];
            foreach ($items as $item) {
                $confirmed[(string) ($item['line'] ?? '')] = (float) ($item['quantity'] ?? 0);
            }
            foreach ($lines as $line) {
                $id = (string) ($line['id'] ?? '');
                if (!array_key_exists($id, $confirmed)) {
                    return false;
                }
                if (($leaf['match'] ?? null) === 'entitlement' && isset($line['entitled']) && $confirmed[$id] > (float) $line['entitled']) {
                    return false;
                }
            }

            return true;
        };
    }
}
