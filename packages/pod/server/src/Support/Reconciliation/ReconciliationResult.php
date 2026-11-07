<?php

namespace Fleetbase\Pod\Support\Reconciliation;

final class ReconciliationResult
{
    /**
     * @param list<array{sku: string, lot: ?string, opening: float, handed_over: float, closing: float, discrepancies: float, variance: float, balanced: bool}> $lines
     */
    public function __construct(
        public readonly bool $balanced,
        public readonly array $lines,
    ) {
    }

    /**
     * @return list<array> the lines that don't balance
     */
    public function unbalanced(): array
    {
        return array_values(array_filter($this->lines, fn ($line) => !$line['balanced']));
    }

    public function toArray(): array
    {
        return ['balanced' => $this->balanced, 'lines' => $this->lines];
    }
}
