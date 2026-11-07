<?php

namespace Fleetbase\Pod\Support\Transport;

/**
 * Compares what was loaded with what arrived, per item and lot, and proposes
 * the discrepancies that must be explained before a trip can close
 * (AGRI-INPUTS.md §4.1, REPORTING-AUDIT.md AR-01).
 */
final class ReceiptCheck
{
    /**
     * @param list<array{sku: string, lot?: string, loaded: float}>                       $loaded
     * @param list<array{sku: string, lot?: string, good: float, damaged?: float}>        $received
     *
     * @return list<array{sku: string, lot: string, loaded: float, good: float, damaged: float, short: float, reason: ?string}>
     *   short > 0: missing; short < 0: more arrived than was loaded
     */
    public static function compare(array $loaded, array $received): array
    {
        $lines = [];
        foreach ($loaded as $line) {
            $key         = $line['sku'] . '|' . ($line['lot'] ?? '');
            $lines[$key] = ['sku' => $line['sku'], 'lot' => (string) ($line['lot'] ?? ''), 'loaded' => (float) $line['loaded'], 'good' => 0.0, 'damaged' => 0.0];
        }

        foreach ($received as $line) {
            $key = $line['sku'] . '|' . ($line['lot'] ?? '');
            if (!isset($lines[$key])) {
                // Something arrived that wasn't loaded: count it, it shows up as excess.
                $lines[$key] = ['sku' => $line['sku'], 'lot' => (string) ($line['lot'] ?? ''), 'loaded' => 0.0, 'good' => 0.0, 'damaged' => 0.0];
            }
            foreach (['good', 'damaged'] as $field) {
                $value = (float) ($line[$field] ?? 0);
                if ($value < 0) {
                    throw new \InvalidArgumentException("A received quantity for {$line['sku']} is negative.");
                }
                $lines[$key][$field] += $value;
            }
        }

        ksort($lines);

        return array_values(array_map(function ($line) {
            $line['short']  = round($line['loaded'] - $line['good'] - $line['damaged'], 6);
            $line['reason'] = match (true) {
                $line['short'] > 0 => 'short_delivered',
                $line['short'] < 0 => 'excess',
                default            => null,
            };

            return $line;
        }, $lines));
    }
}
