<?php

namespace Fleetbase\Pod\Support\Import;

use Fleetbase\Pod\Support\Text\Text;

/**
 * Suggests which spreadsheet column holds which beneficiary field, and applies
 * a confirmed mapping to rows. Mappings can be saved and reused for files in
 * the same layout (AGRI-INPUTS.md §7.1).
 */
final class ColumnMapper
{
    /**
     * Beneficiary fields that can be imported, with header words people use for them.
     * Order matters: earlier fields claim a header first.
     */
    public const FIELDS = [
        'farmer_ref'    => ['farmer id', 'farmer ref', 'farmer reference', 'farmer code', 'farmer no', 'farmer number', 'beneficiary id', 'beneficiary code', 'beneficiary no', 'reg no', 'registration number', 'registration no', 'serial id'],
        'full_name'     => ['name', 'full name', 'farmer name', 'farmers name', "farmer's name", 'name of farmer', 'beneficiary name', 'name of beneficiary', 'names'],
        'first_name'    => ['first name', 'firstname', 'given name', 'forename'],
        'middle_name'   => ['middle name', 'other name', 'other names', 'middlename'],
        'last_name'     => ['last name', 'lastname', 'surname', 'family name'],
        'sex'           => ['sex', 'gender', 'm f', 'male female', 'sex m f'],
        'age'           => ['age', 'age years', 'age yrs', 'age in years'],
        'birth_year'    => ['year of birth', 'birth year', 'yob'],
        'date_of_birth' => ['date of birth', 'dob', 'birth date', 'birthdate'],
        'phone'         => ['phone', 'phone number', 'phone no', 'telephone', 'tel', 'mobile', 'mobile number', 'mobile no', 'contact', 'contact number', 'contact no', 'cell', 'gsm'],
        'id_type'       => ['id type', 'type of id', 'id card type', 'identification type', 'id document'],
        'id_number'     => ['id number', 'id no', 'id', 'nin', 'national id', 'national id number', 'voter id number', 'voter id', 'identification number', 'id card number'],
        'region'        => ['region', 'province'],
        'district'      => ['district'],
        'chiefdom'      => ['chiefdom', 'chiefdom name'],
        'section'       => ['section', 'section name'],
        'village'       => ['village', 'community', 'town', 'village town', 'locality', 'village community'],
        'group'         => ['group', 'farmer group', 'fbo', 'group name', 'cooperative', 'association', 'farmer based organisation', 'farmer based organization'],
        'latitude'      => ['latitude', 'lat', 'gps latitude', 'y'],
        'longitude'     => ['longitude', 'lon', 'long', 'lng', 'gps longitude', 'x'],
        'household_size'=> ['household size', 'hh size', 'household members', 'no of household members'],
        'farm_size'     => ['farm size', 'farm size ha', 'farm size acres', 'land size', 'area cultivated', 'acreage', 'hectares'],
        'crop'          => ['crop', 'main crop', 'crops', 'value chain'],
        'notes'         => ['notes', 'remarks', 'comment', 'comments'],
    ];

    /**
     * Suggest a mapping for a file's headers: field => header.
     *
     * @param list<string> $headers
     *
     * @return array<string, string>
     */
    public function suggest(array $headers): array
    {
        $byKey = [];
        foreach ($headers as $header) {
            $key = Text::key($header);
            if ($key !== '' && !isset($byKey[$key])) {
                $byKey[$key] = $header;
            }
        }

        $mapping = [];
        $used    = [];

        // Pass 1: exact synonym matches.
        foreach (self::FIELDS as $field => $synonyms) {
            foreach ($synonyms as $synonym) {
                $key = Text::key($synonym);
                if (isset($byKey[$key]) && !isset($used[$byKey[$key]])) {
                    $mapping[$field]          = $byKey[$key];
                    $used[$byKey[$key]] = true;
                    continue 2;
                }
            }
        }

        // Pass 2: a header that contains a synonym of three or more letters as a word
        // ("Name of Farmer (Surname)" -> last_name before full_name).
        foreach (self::FIELDS as $field => $synonyms) {
            if (isset($mapping[$field])) {
                continue;
            }
            foreach ($byKey as $key => $header) {
                if (isset($used[$header])) {
                    continue;
                }
                foreach ($synonyms as $synonym) {
                    $synonymKey = Text::key($synonym);
                    if (strlen($synonymKey) >= 3 && preg_match('/\b' . preg_quote($synonymKey, '/') . '\b/', $key)) {
                        $mapping[$field] = $header;
                        $used[$header]   = true;
                        continue 3;
                    }
                }
            }
        }

        // A sheet with "Name" next to "Surname" uses "Name" for the first name.
        if (isset($mapping['full_name']) && (isset($mapping['first_name']) || isset($mapping['last_name']))) {
            $header = $mapping['full_name'];
            unset($mapping['full_name']);
            if (!isset($mapping['first_name'])) {
                $mapping['first_name'] = $header;
            }
        }

        return $mapping;
    }

    /**
     * Check a mapping before use.
     *
     * @param array<string, string> $mapping field => header
     * @param list<string>          $headers
     *
     * @return list<string> problems; empty when the mapping can be used
     */
    public function validate(array $mapping, array $headers): array
    {
        $problems = [];

        foreach ($mapping as $field => $header) {
            if (!array_key_exists($field, self::FIELDS)) {
                $problems[] = "Unknown field [{$field}].";
            }
            if (!in_array($header, $headers, true)) {
                $problems[] = "Column [{$header}] isn't in the file.";
            }
        }

        $counts = array_count_values(array_values($mapping));
        foreach ($counts as $header => $count) {
            if ($count > 1) {
                $problems[] = "Column [{$header}] is mapped to more than one field.";
            }
        }

        if (!isset($mapping['full_name']) && !isset($mapping['first_name']) && !isset($mapping['last_name'])) {
            $problems[] = 'Map a name column: either a full name, or first and last name.';
        }
        if (!isset($mapping['district'])) {
            $problems[] = 'Map the district column.';
        }
        if (!isset($mapping['sex'])) {
            $problems[] = 'Map the sex column (needed for reporting by sex).';
        }

        return $problems;
    }

    /**
     * @param array<string, string> $mapping field => header
     * @param array<string, mixed>  $row     header => value
     *
     * @return array<string, string> field => value
     */
    public function apply(array $mapping, array $row): array
    {
        $out = [];
        foreach ($mapping as $field => $header) {
            $out[$field] = Cell::value($row[$header] ?? null);
        }

        return $out;
    }
}
