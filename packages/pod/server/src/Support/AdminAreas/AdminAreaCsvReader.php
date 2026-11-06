<?php

namespace Fleetbase\Pod\Support\AdminAreas;

/**
 * Reads an admin area seed file in the format of
 * server/data/seed/sierra-leone/admin-areas.csv.
 *
 * Required columns: level, pcode, name, parent_pcode.
 * Optional: level_label, source_name, center_lat, center_lon, review_flag.
 */
final class AdminAreaCsvReader
{
    public const REQUIRED = ['level', 'pcode', 'name', 'parent_pcode'];

    /**
     * @param string $countryRootPcode the parent p-code used for top-level areas (e.g. "SL"); stored as null
     */
    public function read(string $path, string $country, ?string $countryRootPcode = null): AdminAreaSet
    {
        if (!is_readable($path)) {
            throw new \RuntimeException("Admin area file [{$path}] can't be read.");
        }

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, escape: '\\');
        if ($header === false) {
            throw new \RuntimeException("Admin area file [{$path}] is empty.");
        }
        $header = array_map(fn ($h) => strtolower(trim((string) $h, " \t\n\r\0\x0B\u{FEFF}")), $header);

        $missing = array_diff(self::REQUIRED, $header);
        if ($missing) {
            throw new \RuntimeException('Admin area file is missing columns: ' . implode(', ', $missing));
        }

        $root = $countryRootPcode ?? strtoupper($country);
        $set  = new AdminAreaSet();
        $line = 1;
        while (($row = fgetcsv($handle, escape: '\\')) !== false) {
            $line++;
            if ($row === [null] || $row === []) {
                continue;
            }
            $data = array_combine($header, array_pad($row, count($header), null));

            $parent = trim((string) $data['parent_pcode']);
            $flags  = array_values(array_filter(explode(';', (string) ($data['review_flag'] ?? ''))));

            $set->add(new AdminArea(
                country: strtoupper($country),
                level: (int) $data['level'],
                pcode: trim((string) $data['pcode']),
                name: trim((string) $data['name']),
                parentPcode: ($parent === '' || $parent === $root) ? null : $parent,
                sourceName: ($data['source_name'] ?? '') !== '' ? $data['source_name'] : null,
                lat: is_numeric($data['center_lat'] ?? null) ? (float) $data['center_lat'] : null,
                lon: is_numeric($data['center_lon'] ?? null) ? (float) $data['center_lon'] : null,
                reviewFlags: $flags,
                levelLabel: ($data['level_label'] ?? '') !== '' ? $data['level_label'] : null,
            ));
        }
        fclose($handle);

        return $set;
    }
}
