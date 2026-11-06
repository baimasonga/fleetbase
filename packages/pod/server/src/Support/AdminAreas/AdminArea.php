<?php

namespace Fleetbase\Pod\Support\AdminAreas;

/**
 * One official administrative area (region, district, chiefdom, section...),
 * keyed by its p-code.
 */
final class AdminArea
{
    /**
     * @param list<string> $reviewFlags e.g. name_corrected, possibly_truncated
     */
    public function __construct(
        public readonly string $country,
        public readonly int $level,
        public readonly string $pcode,
        public readonly string $name,
        public readonly ?string $parentPcode,
        public readonly ?string $sourceName = null,
        public readonly ?float $lat = null,
        public readonly ?float $lon = null,
        public readonly array $reviewFlags = [],
        public readonly ?string $levelLabel = null,
    ) {
    }

    public function isPossiblyTruncated(): bool
    {
        return in_array('possibly_truncated', $this->reviewFlags, true);
    }

    public function toArray(): array
    {
        return [
            'country'      => $this->country,
            'level'        => $this->level,
            'level_label'  => $this->levelLabel,
            'pcode'        => $this->pcode,
            'name'         => $this->name,
            'source_name'  => $this->sourceName,
            'parent_pcode' => $this->parentPcode,
            'center_lat'   => $this->lat,
            'center_lon'   => $this->lon,
            'review_flags' => $this->reviewFlags,
        ];
    }
}
