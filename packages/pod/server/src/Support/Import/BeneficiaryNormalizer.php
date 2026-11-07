<?php

namespace Fleetbase\Pod\Support\Import;

use Fleetbase\Pod\Support\AdminAreas\AdminAreaMatcher;
use Fleetbase\Pod\Support\AdminAreas\AdminAreaSet;
use Fleetbase\Pod\Support\AdminAreas\PlaceMatch;
use Fleetbase\Pod\Support\Countries\CountryProfile;
use Fleetbase\Pod\Support\Text\Text;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * Cleans one mapped spreadsheet row into beneficiary fields
 * (AGRI-INPUTS.md §7.1 step 1, REPORTING-AUDIT.md §9).
 *
 * Privacy: full ID numbers are never returned. Only the ID type, the last 4
 * characters, and a keyed hash (for duplicate checks) leave this class.
 */
final class BeneficiaryNormalizer
{
    private const PLACE_FIELDS = [1 => 'region', 2 => 'district', 3 => 'chiefdom', 4 => 'section'];

    private PhoneNumberUtil $phones;

    private array $options;

    public function __construct(
        private CountryProfile $country,
        private AdminAreaMatcher $matcher,
        private AdminAreaSet $areas,
        private string $idHashKey,
        private int $referenceYear,
        array $options = [],
    ) {
        if ($idHashKey === '') {
            throw new \InvalidArgumentException('An ID hash key is required.');
        }
        $this->phones  = PhoneNumberUtil::getInstance();
        $this->options = $options + [
            'min_age'        => 10,
            'warn_below_age' => 15,
            'max_age'        => 110,
        ];
    }

    /**
     * @param array<string, string> $row field => value, from ColumnMapper::apply()
     */
    public function normalize(int $rowNumber, array $row): NormalizedRow
    {
        $issues = [];
        $data   = [];

        $data['farmer_ref'] = Text::squish($row['farmer_ref'] ?? '') ?: null;
        $data['name']       = $this->name($row, $issues);
        $data['sex']        = $this->sex($row['sex'] ?? '', $issues);
        $data['birth_year'] = $this->birthYear($row, $issues);

        [$data['phone'], $data['phone_extra']] = $this->phone($row['phone'] ?? '', $issues);

        [$data['id_type'], $data['id_last4'], $data['id_hash']] = $this->identity($row['id_type'] ?? '', $row['id_number'] ?? '', $issues);

        $places = $this->places($row, $issues);
        foreach (self::PLACE_FIELDS as $level => $field) {
            $data['admin_' . $level] = isset($places[$level]) && $places[$level]->matched() ? $places[$level]->area->pcode : null;
        }

        $data['village'] = Text::titleName($row['village'] ?? '') ?: null;
        if ($data['village'] === null) {
            $issues[] = RowIssue::warning('village', 'village_missing', 'No village given. It can be added during field verification.');
        }

        $data['group']          = Text::squish($row['group'] ?? '') ?: null;
        $data['crop']           = Text::squish($row['crop'] ?? '') ?: null;
        $data['farm_size']      = Text::squish($row['farm_size'] ?? '') ?: null;
        $data['notes']          = Text::squish($row['notes'] ?? '') ?: null;
        $data['household_size'] = $this->positiveInt($row['household_size'] ?? '', 'household_size', $issues);

        [$data['latitude'], $data['longitude']] = $this->location($row, $places, $issues);

        return new NormalizedRow($rowNumber, $data, $issues, $places, $row);
    }

    private function name(array $row, array &$issues): ?string
    {
        $full = Text::squish($row['full_name'] ?? '');
        if ($full === '') {
            $full = Text::squish(implode(' ', array_filter([
                $row['first_name'] ?? '',
                $row['middle_name'] ?? '',
                $row['last_name'] ?? '',
            ], fn ($part) => Text::squish($part) !== '')));
        }

        if ($full === '') {
            $issues[] = RowIssue::error('name', 'name_missing', 'The name is empty.');

            return null;
        }

        if (preg_match('/\d/', $full)) {
            $issues[] = RowIssue::warning('name', 'name_has_digits', 'The name contains numbers. Check it was typed correctly.');
        }
        if (!str_contains($full, ' ')) {
            $issues[] = RowIssue::warning('name', 'name_single_word', 'Only one name is given.');
        }

        return Text::titleName($full);
    }

    private function sex(string $raw, array &$issues): ?string
    {
        $key = Text::key($raw);
        $map = [
            'm' => 'male', 'male' => 'male', 'man' => 'male', 'boy' => 'male', 'mr' => 'male',
            'f' => 'female', 'female' => 'female', 'woman' => 'female', 'w' => 'female', 'girl' => 'female', 'fem' => 'female', 'mrs' => 'female', 'ms' => 'female',
        ];

        if ($key === '') {
            $issues[] = RowIssue::error('sex', 'sex_missing', 'Sex is empty. It is needed for reporting by sex.');

            return null;
        }
        if (!isset($map[$key])) {
            $issues[] = RowIssue::error('sex', 'sex_unknown', "Sex \"{$raw}\" isn't recognised. Use M or F.");

            return null;
        }

        return $map[$key];
    }

    private function birthYear(array $row, array &$issues): ?int
    {
        $year = null;

        $rawYear = Text::squish($row['birth_year'] ?? '');
        $rawDob  = Text::squish($row['date_of_birth'] ?? '');
        $rawAge  = Text::squish($row['age'] ?? '');

        if ($rawYear !== '' && preg_match('/^(19|20)\d{2}$/', $rawYear)) {
            $year = (int) $rawYear;
        } elseif ($rawDob !== '') {
            $year = $this->yearFromDate($rawDob);
            if ($year === null) {
                $issues[] = RowIssue::warning('date_of_birth', 'dob_unreadable', "Date of birth \"{$rawDob}\" can't be read.");
            }
        }

        if ($year === null && $rawAge !== '') {
            if (is_numeric($rawAge) && (float) $rawAge >= 0 && (float) $rawAge < 150) {
                $year = $this->referenceYear - (int) floor((float) $rawAge);
            } else {
                $issues[] = RowIssue::warning('age', 'age_unreadable', "Age \"{$rawAge}\" isn't a number.");
            }
        }

        if ($year === null) {
            $issues[] = RowIssue::warning('age', 'age_missing', 'No age or year of birth. Reports by age band will show this person as "unknown".');

            return null;
        }

        $age = $this->referenceYear - $year;
        if ($age < $this->options['min_age'] || $age > $this->options['max_age']) {
            $issues[] = RowIssue::error('age', 'age_out_of_range', "Age {$age} is outside {$this->options['min_age']}–{$this->options['max_age']}.");

            return null;
        }
        if ($age < $this->options['warn_below_age']) {
            $issues[] = RowIssue::warning('age', 'age_young', "Age {$age} is under {$this->options['warn_below_age']}. Check the farmer's eligibility.");
        }

        return $year;
    }

    private function yearFromDate(string $raw): ?int
    {
        // Excel stores dates as day numbers counted from 30/12/1899.
        if (is_numeric($raw) && (float) $raw > 1000 && (float) $raw < 80000) {
            $date = (new \DateTimeImmutable('1899-12-30'))->modify('+' . (int) $raw . ' days');

            return (int) $date->format('Y');
        }

        // ISO (2024-03-12), or day first as written in West Africa (12/03/1985, 12-03-1985, 12.03.85).
        if (preg_match('/^(\d{4})-\d{1,2}-\d{1,2}/', $raw, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/^\d{1,2}[\/\-.]\d{1,2}[\/\-.](\d{2}|\d{4})$/', $raw, $m)) {
            $year = (int) $m[1];
            if ($year < 100) {
                $year += ($year > ($this->referenceYear % 100)) ? 1900 : 2000;
            }

            return $year;
        }

        return null;
    }

    /**
     * @return array{0: ?string, 1: ?string} [E.164 phone, other numbers given in the cell]
     */
    private function phone(string $raw, array &$issues): array
    {
        $raw = Text::squish($raw);
        if ($raw === '') {
            return [null, null];
        }

        $parts = preg_split('/\s*(?:\/|,|;|\bor\b|&)\s*/i', $raw) ?: [$raw];
        $parts = array_values(array_filter($parts, fn ($p) => trim($p) !== ''));

        $valid = [];
        foreach ($parts as $part) {
            $number = $this->parsePhone($part);
            if ($number !== null) {
                $valid[] = $number;
            }
        }

        if (!$valid) {
            $issues[] = RowIssue::warning('phone', 'phone_invalid', "Phone \"{$raw}\" isn't a valid {$this->country->name} number. The phone is left empty.");

            return [null, null];
        }

        if (count($parts) > 1) {
            $issues[] = RowIssue::warning('phone', 'phone_multiple', 'More than one phone number is given. The first valid one is used.');
        }

        return [$valid[0], count($valid) > 1 ? implode(', ', array_slice($valid, 1)) : null];
    }

    private function parsePhone(string $raw): ?string
    {
        $digits = preg_replace('/[^\d+]/', '', $raw) ?? '';
        if ($digits === '') {
            return null;
        }
        // "00232..." international prefix
        if (str_starts_with($digits, '00')) {
            $digits = '+' . substr($digits, 2);
        }

        try {
            $number = $this->phones->parse($digits, $this->country->code);
        } catch (NumberParseException) {
            return null;
        }

        if (!$this->phones->isValidNumber($number)) {
            return null;
        }

        return $this->phones->format($number, PhoneNumberFormat::E164);
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string} [id type, last 4, keyed hash]
     */
    private function identity(string $rawType, string $rawNumber, array &$issues): array
    {
        $number = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $rawNumber) ?? '');
        $type   = $this->country->resolveIdType($rawType);

        if ($number === '') {
            if ($type !== null) {
                $issues[] = RowIssue::warning('id_number', 'id_number_missing', 'An ID type is given without a number. The ID is left empty.');
            }

            return [null, null, null];
        }

        if ($type === null) {
            $issues[] = RowIssue::warning('id_type', 'id_type_missing', 'An ID number is given without its type. Recorded as "Other".');
            $type = 'other';
        } elseif ($type === 'other' && Text::key($rawType) !== 'other') {
            $issues[] = RowIssue::warning('id_type', 'id_type_unknown', "ID type \"{$rawType}\" isn't recognised. Recorded as \"Other\".");
        }

        if (strlen($number) < 4) {
            $issues[] = RowIssue::warning('id_number', 'id_number_short', 'The ID number is shorter than 4 characters. The ID is left empty.');

            return [null, null, null];
        }

        $hash = hash_hmac('sha256', $type . '|' . $number, $this->idHashKey);

        return [$type, substr($number, -4), $hash];
    }

    /**
     * @return array<int, PlaceMatch>
     */
    private function places(array $row, array &$issues): array
    {
        $names = [];
        foreach (self::PLACE_FIELDS as $level => $field) {
            if (array_key_exists($field, $row) && Text::squish($row[$field]) !== '') {
                $names[$level] = $row[$field];
            }
        }

        if (!isset($names[2])) {
            $issues[] = RowIssue::error('district', 'district_missing', 'The district is empty.');

            return $names ? $this->matcher->matchHierarchy($names) : [];
        }

        $matches = $this->matcher->matchHierarchy($names);

        // A wrong region shouldn't hide a correct district: retry the district on its own.
        if (!$matches[2]->matched() && isset($matches[1]) && $matches[1]->matched()) {
            $retry = $this->matcher->matchHierarchy(array_diff_key($names, [1 => true]));
            if ($retry[2]->matched()) {
                $issues[] = RowIssue::warning('region', 'region_mismatch', "District \"{$names[2]}\" isn't in region \"{$names[1]}\". The region is taken from the district.");
                $matches  = $retry;
            }
        }

        // Fill in the levels above the deepest match from the official hierarchy.
        $deepest = null;
        foreach ($matches as $level => $match) {
            if ($match->matched()) {
                $deepest = $match->area->pcode;
            }
        }
        if ($deepest !== null) {
            foreach ($this->areas->lineage($deepest) as $level => $area) {
                if (!isset($matches[$level]) || !$matches[$level]->matched()) {
                    $matches[$level] = new PlaceMatch($level, $names[$level] ?? '', PlaceMatch::ALIAS, $area, 1.0);
                }
            }
            ksort($matches);
        }

        foreach ($matches as $level => $match) {
            $label = $this->country->adminLevelLabel($level);
            $field = self::PLACE_FIELDS[$level] ?? ('admin_' . $level);

            if ($match->method === PlaceMatch::NONE) {
                $hint = $match->suggestions ? ' Closest: ' . implode(', ', $match->suggestions) . '.' : '';
                if ($level === 2) {
                    $issues[] = RowIssue::error($field, 'place_unmatched', "{$label} \"{$match->input}\" isn't on the official list.{$hint}");
                } else {
                    $issues[] = RowIssue::warning($field, 'place_unmatched', "{$label} \"{$match->input}\" isn't on the official list.{$hint}");
                }
            } elseif ($match->method === PlaceMatch::FUZZY) {
                $issues[] = RowIssue::warning($field, 'place_fuzzy', "{$label} \"{$match->input}\" was matched to \"{$match->area->name}\". Check this is right.");
            }
        }

        return $matches;
    }

    /**
     * @param array<int, PlaceMatch> $places
     *
     * @return array{0: ?float, 1: ?float}
     */
    private function location(array $row, array $places, array &$issues): array
    {
        $lat = Text::squish($row['latitude'] ?? '');
        $lon = Text::squish($row['longitude'] ?? '');
        if ($lat === '' && $lon === '') {
            return [null, null];
        }

        if (!is_numeric($lat) || !is_numeric($lon) || abs((float) $lat) > 90 || abs((float) $lon) > 180) {
            $issues[] = RowIssue::warning('latitude', 'gps_invalid', 'The GPS coordinates are not valid numbers. They are left empty.');

            return [null, null];
        }

        $lat = (float) $lat;
        $lon = (float) $lon;

        // Latitude and longitude swapped is a common spreadsheet mistake. In West Africa
        // latitude is positive (north) and longitude mostly negative (west of Greenwich).
        $district = isset($places[2]) && $places[2]->matched() ? $places[2]->area : null;
        if ($district && $district->lat !== null && $district->lon !== null) {
            $distance = self::distanceKm($lat, $lon, $district->lat, $district->lon);
            $swapped  = self::distanceKm($lon, $lat, $district->lat, $district->lon);
            if ($distance > 150 && $swapped < 150) {
                $issues[] = RowIssue::warning('latitude', 'gps_swapped', 'Latitude and longitude look swapped. They were switched.');
                [$lat, $lon] = [$lon, $lat];
            } elseif ($distance > 150) {
                $issues[] = RowIssue::warning('latitude', 'gps_far', sprintf('The GPS point is %d km from the centre of %s. Check it.', $distance, $district->name));
            }
        }

        return [round($lat, 7), round($lon, 7)];
    }

    private function positiveInt(string $raw, string $field, array &$issues): ?int
    {
        $raw = Text::squish($raw);
        if ($raw === '') {
            return null;
        }
        if (!ctype_digit($raw) || (int) $raw === 0) {
            $issues[] = RowIssue::warning($field, $field . '_invalid', "\"{$raw}\" isn't a whole number. Left empty.");

            return null;
        }

        return (int) $raw;
    }

    public static function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r    = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a    = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
