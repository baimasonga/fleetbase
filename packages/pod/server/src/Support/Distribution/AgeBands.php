<?php

namespace Fleetbase\Pod\Support\Distribution;

/**
 * Age bands for reporting (REPORTING-AUDIT.md §3): 15–24, 25–35 (youth up to
 * 35), 36–59, 60+, and unknown.
 */
final class AgeBands
{
    public static function band(?int $birthYear, int $year): string
    {
        if ($birthYear === null) {
            return 'unknown';
        }
        $age = $year - $birthYear;

        return match (true) {
            $age < 15  => 'under_15',
            $age <= 24 => '15_24',
            $age <= 35 => '25_35',
            $age <= 59 => '36_59',
            default    => '60_plus',
        };
    }

    /**
     * Count people by sex and age band.
     *
     * @param list<array{sex: ?string, birth_year: ?int}> $people
     *
     * @return array{total: int, by_sex: array<string, int>, by_age_band: array<string, int>, youth_15_35: int}
     */
    public static function totals(array $people, int $year): array
    {
        $bySex  = ['female' => 0, 'male' => 0, 'unknown' => 0];
        $byBand = ['under_15' => 0, '15_24' => 0, '25_35' => 0, '36_59' => 0, '60_plus' => 0, 'unknown' => 0];

        foreach ($people as $person) {
            $sex = in_array($person['sex'] ?? null, ['female', 'male'], true) ? $person['sex'] : 'unknown';
            $bySex[$sex]++;
            $byBand[self::band($person['birth_year'] ?? null, $year)]++;
        }

        return [
            'total'       => count($people),
            'by_sex'      => $bySex,
            'by_age_band' => $byBand,
            'youth_15_35' => $byBand['15_24'] + $byBand['25_35'],
        ];
    }
}
