<?php

namespace Fleetbase\Pod\Support\AdminAreas;

/**
 * An in-memory set of admin areas for one country, indexed for lookups.
 */
final class AdminAreaSet implements \Countable
{
    /** @var array<string, AdminArea> */
    private array $byPcode = [];

    /** @var array<string, list<AdminArea>> parent pcode ('' for top level) => children */
    private array $children = [];

    /** @var array<int, list<AdminArea>> */
    private array $byLevel = [];

    /**
     * @param iterable<AdminArea> $areas
     */
    public function __construct(iterable $areas = [])
    {
        foreach ($areas as $area) {
            $this->add($area);
        }
    }

    public function add(AdminArea $area): void
    {
        if (isset($this->byPcode[$area->pcode])) {
            throw new \InvalidArgumentException("Duplicate p-code [{$area->pcode}].");
        }

        $this->byPcode[$area->pcode]                    = $area;
        $this->children[$area->parentPcode ?? ''][]     = $area;
        $this->byLevel[$area->level][]                  = $area;
    }

    public function find(string $pcode): ?AdminArea
    {
        return $this->byPcode[$pcode] ?? null;
    }

    /**
     * @return list<AdminArea>
     */
    public function childrenOf(?string $parentPcode): array
    {
        return $this->children[$parentPcode ?? ''] ?? [];
    }

    /**
     * @return list<AdminArea>
     */
    public function atLevel(int $level): array
    {
        return $this->byLevel[$level] ?? [];
    }

    /**
     * Areas at a level, limited to those under an ancestor (any number of levels up).
     *
     * @return list<AdminArea>
     */
    public function atLevelUnder(int $level, ?string $ancestorPcode): array
    {
        if ($ancestorPcode === null) {
            return $this->atLevel($level);
        }

        return array_values(array_filter(
            $this->atLevel($level),
            fn (AdminArea $area) => $this->isUnder($area, $ancestorPcode)
        ));
    }

    public function isUnder(AdminArea $area, string $ancestorPcode): bool
    {
        $parent = $area->parentPcode;
        while ($parent !== null) {
            if ($parent === $ancestorPcode) {
                return true;
            }
            $parent = $this->byPcode[$parent]->parentPcode ?? null;
        }

        return false;
    }

    /**
     * The chain from the top level down to this area, keyed by level.
     *
     * @return array<int, AdminArea>
     */
    public function lineage(string $pcode): array
    {
        $chain = [];
        $area  = $this->find($pcode);
        while ($area !== null) {
            $chain[$area->level] = $area;
            $area                = $area->parentPcode ? $this->find($area->parentPcode) : null;
        }
        ksort($chain);

        return $chain;
    }

    public function maxLevel(): int
    {
        return $this->byLevel ? max(array_keys($this->byLevel)) : 0;
    }

    public function count(): int
    {
        return count($this->byPcode);
    }

    /**
     * @return array<string, AdminArea>
     */
    public function all(): array
    {
        return $this->byPcode;
    }
}
