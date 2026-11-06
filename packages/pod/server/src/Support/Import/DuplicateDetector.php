<?php

namespace Fleetbase\Pod\Support\Import;

use Fleetbase\Pod\Support\Text\Text;

/**
 * Finds duplicate beneficiaries within an import and against people already
 * registered (AGRI-INPUTS.md §7.1, REPORTING-AUDIT.md §9).
 *
 * - Exact duplicate: same name, sex, birth year and place. Merged automatically.
 * - Likely duplicate: same phone, same ID, or a very similar name in the same
 *   place with the same sex and a close birth year. Sent to a person to decide.
 */
final class DuplicateDetector
{
    public const SAME_PHONE   = 'same_phone';
    public const SAME_ID      = 'same_id';
    public const SIMILAR_NAME = 'similar_name';

    public function __construct(
        private float $nameThreshold = 0.92,
        private int $birthYearTolerance = 2,
    ) {
    }

    /**
     * The key for exact duplicates.
     *
     * @param array<string, mixed> $data normalised fields
     */
    public static function exactKey(array $data, ?string $placePcode): string
    {
        $nameParts = explode(' ', Text::key($data['name'] ?? ''));
        sort($nameParts);

        return implode('|', [
            implode(' ', $nameParts),
            $data['sex'] ?? '',
            $data['birth_year'] ?? '',
            $placePcode ?? '',
            Text::key($data['village'] ?? ''),
        ]);
    }

    /**
     * @param array<int, array{data: array, place: ?string}>    $rows     row number => normalised data and deepest p-code
     * @param array<string, array{data: array, place: ?string}> $existing beneficiary id => the same shape
     *
     * @return array{
     *   exact: array<int, int>,
     *   existing: array<int, string>,
     *   likely: list<array{row: int, other_row: ?int, other_existing: ?string, reasons: list<string>, score: float}>
     * }
     *   exact:    duplicate row => the earlier row it repeats
     *   existing: row => existing beneficiary id it repeats exactly
     *   likely:   pairs for review
     */
    public function detect(array $rows, array $existing = []): array
    {
        ksort($rows);

        $existingExact = [];
        foreach ($existing as $id => $record) {
            $existingExact[self::exactKey($record['data'], $record['place'])] = (string) $id;
        }

        $exact      = [];
        $existingOf = [];
        $firstByKey = [];
        $kept       = [];

        foreach ($rows as $number => $record) {
            $key = self::exactKey($record['data'], $record['place']);
            if (isset($existingExact[$key])) {
                $existingOf[$number] = $existingExact[$key];
                continue;
            }
            if (isset($firstByKey[$key])) {
                $exact[$number] = $firstByKey[$key];
                continue;
            }
            $firstByKey[$key] = $number;
            $kept[$number]    = $record;
        }

        // Candidate pool: rows kept from this file, then existing beneficiaries.
        $pool = [];
        foreach ($kept as $number => $record) {
            $pool[] = ['row' => $number, 'existing' => null] + $record;
        }
        foreach ($existing as $id => $record) {
            $pool[] = ['row' => null, 'existing' => (string) $id] + $record;
        }

        $pairs = [];
        $add   = function (array $a, array $b, string $reason, float $score = 1.0) use (&$pairs) {
            // Only report pairs that involve at least one new row.
            if ($a['row'] === null && $b['row'] === null) {
                return;
            }
            // Put the new row (or the later row) first.
            if ($a['row'] === null || ($b['row'] !== null && $b['row'] > $a['row'])) {
                [$a, $b] = [$b, $a];
            }
            $id = $a['row'] . '|' . ($b['row'] ?? 'e' . $b['existing']);
            if (!isset($pairs[$id])) {
                $pairs[$id] = ['row' => $a['row'], 'other_row' => $b['row'], 'other_existing' => $b['existing'], 'reasons' => [], 'score' => 0.0];
            }
            if (!in_array($reason, $pairs[$id]['reasons'], true)) {
                $pairs[$id]['reasons'][] = $reason;
            }
            $pairs[$id]['score'] = max($pairs[$id]['score'], $score);
        };

        // Same phone or same ID: index once, compare within each bucket.
        foreach (['phone' => self::SAME_PHONE, 'id_hash' => self::SAME_ID] as $field => $reason) {
            $buckets = [];
            foreach ($pool as $entry) {
                $value = $entry['data'][$field] ?? null;
                if ($value) {
                    $buckets[$value][] = $entry;
                }
            }
            foreach ($buckets as $entries) {
                for ($i = 0; $i < count($entries); $i++) {
                    for ($j = $i + 1; $j < count($entries); $j++) {
                        $add($entries[$i], $entries[$j], $reason);
                    }
                }
            }
        }

        // Similar names: compare only within the same place, so large lists stay fast.
        $byPlace = [];
        foreach ($pool as $entry) {
            if (($entry['place'] ?? null) && ($entry['data']['name'] ?? null)) {
                $byPlace[$entry['place']][] = $entry;
            }
        }
        foreach ($byPlace as $entries) {
            $count = count($entries);
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $a = $entries[$i];
                    $b = $entries[$j];
                    if (($a['data']['sex'] ?? null) !== ($b['data']['sex'] ?? null)) {
                        continue;
                    }
                    $yearA = $a['data']['birth_year'] ?? null;
                    $yearB = $b['data']['birth_year'] ?? null;
                    if ($yearA !== null && $yearB !== null && abs($yearA - $yearB) > $this->birthYearTolerance) {
                        continue;
                    }
                    $score = Text::nameSimilarity($a['data']['name'], $b['data']['name']);
                    if ($score >= $this->nameThreshold) {
                        $add($a, $b, self::SIMILAR_NAME, $score);
                    }
                }
            }
        }

        ksort($exact);
        ksort($existingOf);

        return [
            'exact'    => $exact,
            'existing' => $existingOf,
            'likely'   => array_values($pairs),
        ];
    }
}
