<?php

namespace Fleetbase\Pod\Support\Import;

/**
 * The preview of an import: what will happen to each row before anything is
 * saved (AGRI-INPUTS.md §7.1 step 1).
 */
final class ImportPlan
{
    public const ACCEPTED = 'accepted'; // will be created
    public const MERGED   = 'merged';   // exact repeat of an earlier row in the file; not created
    public const EXISTS   = 'exists';   // exact repeat of a beneficiary already registered; not created
    public const REVIEW   = 'review';   // likely duplicate; waits for a person to decide
    public const REJECTED = 'rejected'; // has errors; goes to the fix-up list

    /**
     * @param array<int, array{status: string, row: NormalizedRow, duplicate_of_row: ?int, duplicate_of_existing: ?string, review: list<array>}> $rows
     */
    public function __construct(public readonly array $rows)
    {
    }

    /**
     * @return array<string, int>
     */
    public function summary(): array
    {
        $summary = [
            'total'    => count($this->rows),
            self::ACCEPTED => 0,
            self::MERGED   => 0,
            self::EXISTS   => 0,
            self::REVIEW   => 0,
            self::REJECTED => 0,
            'with_warnings' => 0,
        ];

        foreach ($this->rows as $entry) {
            $summary[$entry['status']]++;
            foreach ($entry['row']->issues as $issue) {
                if (!$issue->isError()) {
                    $summary['with_warnings']++;
                    break;
                }
            }
        }

        return $summary;
    }

    /**
     * @return array<int, array> row number => entry, for one status
     */
    public function withStatus(string $status): array
    {
        return array_filter($this->rows, fn ($entry) => $entry['status'] === $status);
    }

    /**
     * Counts of each issue code, to show the most common problems first.
     *
     * @return array<string, int>
     */
    public function issueCounts(): array
    {
        $counts = [];
        foreach ($this->rows as $entry) {
            foreach ($entry['row']->issues as $issue) {
                $counts[$issue->code] = ($counts[$issue->code] ?? 0) + 1;
            }
        }
        arsort($counts);

        return $counts;
    }
}
