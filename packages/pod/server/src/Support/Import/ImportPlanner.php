<?php

namespace Fleetbase\Pod\Support\Import;

/**
 * Turns a sheet and a confirmed column mapping into an ImportPlan.
 * Nothing is saved here; the plan is shown as a preview first.
 */
final class ImportPlanner
{
    public function __construct(
        private ColumnMapper $mapper,
        private BeneficiaryNormalizer $normalizer,
        private DuplicateDetector $duplicates,
    ) {
    }

    /**
     * @param array<string, string>                             $mapping  field => header
     * @param array<string, array{data: array, place: ?string}> $existing already registered beneficiaries in the programme
     */
    public function plan(SheetData $sheet, array $mapping, array $existing = []): ImportPlan
    {
        $problems = $this->mapper->validate($mapping, $sheet->headers);
        if ($problems) {
            throw new \InvalidArgumentException(implode(' ', $problems));
        }

        $normalized = [];
        foreach ($sheet->associativeRows() as $number => $row) {
            $normalized[$number] = $this->normalizer->normalize($number, $this->mapper->apply($mapping, $row));
        }

        $candidates = [];
        foreach ($normalized as $number => $row) {
            if (!$row->hasErrors()) {
                $candidates[$number] = ['data' => $row->data, 'place' => $row->deepestPcode()];
            }
        }

        $found = $this->duplicates->detect($candidates, $existing);

        $review = [];
        foreach ($found['likely'] as $pair) {
            $review[$pair['row']][] = $pair;
        }

        $entries = [];
        foreach ($normalized as $number => $row) {
            $status = ImportPlan::ACCEPTED;
            if ($row->hasErrors()) {
                $status = ImportPlan::REJECTED;
            } elseif (isset($found['existing'][$number])) {
                $status = ImportPlan::EXISTS;
            } elseif (isset($found['exact'][$number])) {
                $status = ImportPlan::MERGED;
            } elseif (isset($review[$number])) {
                $status = ImportPlan::REVIEW;
            }

            $entries[$number] = [
                'status'                => $status,
                'row'                   => $row,
                'duplicate_of_row'      => $found['exact'][$number] ?? null,
                'duplicate_of_existing' => $found['existing'][$number] ?? null,
                'review'                => $review[$number] ?? [],
            ];
        }

        return new ImportPlan($entries);
    }
}
