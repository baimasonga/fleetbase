<?php

namespace Fleetbase\Pod\Support\Reconciliation;

/**
 * Reconciles stock for a distribution event, door-to-door route or transport
 * leg (AGRI-INPUTS.md §11, REPORTING-AUDIT.md AR-01).
 *
 * Per sku and lot:  opening = handed over + closing + discrepancies
 *
 * Quantities are compared in the line's own unit (bags, kg, litres, doses).
 */
final class StockReconciler
{
    public function __construct(private float $tolerance = 0.0)
    {
        if ($tolerance < 0) {
            throw new \InvalidArgumentException('The tolerance cannot be negative.');
        }
    }

    /**
     * @param list<array{sku: string, lot?: ?string, quantity: float|int}> $opening
     * @param list<array{sku: string, lot?: ?string, quantity: float|int}> $handedOver
     * @param list<array{sku: string, lot?: ?string, quantity: float|int}> $closing
     * @param list<array{sku: string, lot?: ?string, quantity: float|int}> $discrepancies
     */
    public function reconcile(array $opening, array $handedOver, array $closing, array $discrepancies = []): ReconciliationResult
    {
        $lines = [];
        foreach (['opening' => $opening, 'handed_over' => $handedOver, 'closing' => $closing, 'discrepancies' => $discrepancies] as $column => $entries) {
            foreach ($entries as $entry) {
                if (!isset($entry['sku']) || $entry['sku'] === '') {
                    throw new \InvalidArgumentException("A {$column} entry has no sku.");
                }
                if (!is_numeric($entry['quantity'] ?? null) || $entry['quantity'] < 0) {
                    throw new \InvalidArgumentException("A {$column} entry for {$entry['sku']} has an invalid quantity.");
                }
                $key = $entry['sku'] . '|' . ($entry['lot'] ?? '');
                $lines[$key] ??= ['sku' => $entry['sku'], 'lot' => $entry['lot'] ?? null, 'opening' => 0.0, 'handed_over' => 0.0, 'closing' => 0.0, 'discrepancies' => 0.0];
                $lines[$key][$column] += (float) $entry['quantity'];
            }
        }

        ksort($lines);
        $balanced = true;
        foreach ($lines as &$line) {
            // Positive variance: stock is missing. Negative: more was accounted for than was there.
            $line['variance'] = round($line['opening'] - $line['handed_over'] - $line['closing'] - $line['discrepancies'], 6);
            $line['balanced'] = abs($line['variance']) <= $this->tolerance;
            $balanced         = $balanced && $line['balanced'];
        }
        unset($line);

        return new ReconciliationResult($balanced, array_values($lines));
    }
}
