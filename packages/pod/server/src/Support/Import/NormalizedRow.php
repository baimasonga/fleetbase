<?php

namespace Fleetbase\Pod\Support\Import;

use Fleetbase\Pod\Support\AdminAreas\PlaceMatch;

/**
 * One spreadsheet row after cleaning, with the issues found.
 */
final class NormalizedRow
{
    /**
     * @param array<string, mixed>   $data    clean beneficiary fields
     * @param list<RowIssue>         $issues
     * @param array<int, PlaceMatch> $places  level => match
     * @param array<string, string>  $raw     the mapped values as they were in the file
     */
    public function __construct(
        public readonly int $rowNumber,
        public readonly array $data,
        public readonly array $issues,
        public readonly array $places,
        public readonly array $raw,
    ) {
    }

    public function hasErrors(): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->isError()) {
                return true;
            }
        }

        return false;
    }

    public function get(string $field, mixed $default = null): mixed
    {
        return $this->data[$field] ?? $default;
    }

    /**
     * The p-code of the most detailed admin area matched (section, else chiefdom, else district...).
     */
    public function deepestPcode(): ?string
    {
        $levels = array_keys(array_filter($this->places, fn (PlaceMatch $m) => $m->matched()));
        if (!$levels) {
            return null;
        }

        return $this->places[max($levels)]->area->pcode;
    }

    public function toArray(): array
    {
        return [
            'row'    => $this->rowNumber,
            'data'   => $this->data,
            'issues' => array_map(fn (RowIssue $i) => $i->toArray(), $this->issues),
            'places' => array_map(fn (PlaceMatch $m) => $m->toArray(), $this->places),
            'raw'    => $this->raw,
        ];
    }
}
