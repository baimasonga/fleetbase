<?php

namespace Fleetbase\Pod\Support\Transport;

/**
 * Compares the seal numbers fixed at loading with what the receiver finds
 * (AGRI-INPUTS.md §4.1, REPORTING-AUDIT.md AR-02).
 *
 *   intact    every seal present, unbroken, numbers match
 *   broken    a seal is present but broken or tampered with
 *   missing   a seal fixed at loading isn't there
 *   mismatch  a seal is there that wasn't fixed at loading (replaced seal)
 *   none      no seals were used
 */
final class SealCheck
{
    public const INTACT   = 'intact';
    public const BROKEN   = 'broken';
    public const MISSING  = 'missing';
    public const MISMATCH = 'mismatch';
    public const NONE     = 'none';

    /**
     * @param list<string>                         $loaded   seal numbers recorded at loading
     * @param list<array{number: string, intact: bool}> $received what the receiver found
     *
     * @return array{status: string, missing: list<string>, unexpected: list<string>, broken: list<string>}
     */
    public static function compare(array $loaded, array $received): array
    {
        // Seal numbers are typed in by hand at both ends: compare letters and digits only,
        // so "SL-00123", "sl 00123" and "SL00123" are the same seal.
        $key = fn ($number) => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $number) ?? '');

        $loadedKeys = [];
        foreach ($loaded as $number) {
            $loadedKeys[$key($number)] = (string) $number;
        }

        $found  = [];
        $broken = [];
        foreach ($received as $seal) {
            $k         = $key($seal['number'] ?? '');
            $found[$k] = (string) ($seal['number'] ?? '');
            if (empty($seal['intact'])) {
                $broken[] = (string) ($seal['number'] ?? '');
            }
        }

        $missing    = array_values(array_diff_key($loadedKeys, $found));
        $unexpected = array_values(array_diff_key($found, $loadedKeys));

        $status = match (true) {
            !$loadedKeys && !$found => self::NONE,
            (bool) $broken          => self::BROKEN,
            (bool) $missing         => self::MISSING,
            (bool) $unexpected      => self::MISMATCH,
            default                 => self::INTACT,
        };

        return ['status' => $status, 'missing' => $missing, 'unexpected' => $unexpected, 'broken' => $broken];
    }
}
