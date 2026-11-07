<?php

namespace Fleetbase\Pod\Services;

use Fleetbase\Pod\Models\StockMovement;
use Illuminate\Support\Facades\DB;

/**
 * The append-only stock ledger. Stock on hand at a site or open event is the
 * sum of its movements, per item and lot. Taking out more than is there is
 * refused, so stock can never go negative.
 *
 * Callers wrap related movements in one transaction; the balance check locks
 * the holder's rows so two takes can't both pass on the same stock.
 */
class StockLedger
{
    /**
     * @return list<array{sku: string, lot: string, expiry: ?string, quantity: float}>
     */
    public function onHand(string $holderType, string $holderUuid): array
    {
        $rows = StockMovement::where('holder_type', $holderType)
            ->where('holder_uuid', $holderUuid)
            ->groupBy('sku', 'lot')
            ->orderBy('sku')
            ->orderBy('lot')
            ->selectRaw('sku, lot, MAX(expiry) as expiry, SUM(quantity) as quantity')
            ->get();

        return $rows
            ->map(fn ($row) => ['sku' => $row->sku, 'lot' => (string) $row->lot, 'expiry' => $row->expiry ? substr((string) $row->expiry, 0, 10) : null, 'quantity' => round((float) $row->quantity, 3)])
            ->filter(fn ($row) => abs($row['quantity']) > 0.0005)
            ->values()
            ->all();
    }

    public function balance(string $holderType, string $holderUuid, string $sku, string $lot, bool $lock = false): float
    {
        $query = StockMovement::where('holder_type', $holderType)->where('holder_uuid', $holderUuid)->where('sku', $sku)->where('lot', $lot);
        if ($lock && DB::getDriverName() !== 'sqlite') {
            $query->lockForUpdate();
        }

        return round((float) $query->sum('quantity'), 3);
    }

    /**
     * Record one movement. A negative quantity takes stock out and must not
     * exceed what the holder has.
     */
    public function move(array $movement): StockMovement
    {
        foreach (['company_uuid', 'holder_type', 'holder_uuid', 'sku', 'quantity', 'reason'] as $field) {
            if (!isset($movement[$field]) || $movement[$field] === '') {
                throw new \InvalidArgumentException("A stock movement needs {$field}.");
            }
        }
        $movement['lot'] = (string) ($movement['lot'] ?? '');

        if ((float) $movement['quantity'] < 0) {
            $available = $this->balance($movement['holder_type'], $movement['holder_uuid'], $movement['sku'], $movement['lot'], true);
            if ($available + (float) $movement['quantity'] < -0.0005) {
                $lot = $movement['lot'] !== '' ? " lot {$movement['lot']}" : '';
                throw new InsufficientStockException(sprintf('Not enough %s%s: %s on hand, %s needed.', $movement['sku'], $lot, $this->number($available), $this->number(-(float) $movement['quantity'])));
            }
        }

        return StockMovement::create($movement);
    }

    /**
     * Move stock from one holder to another as two movements.
     */
    public function transfer(array $from, array $to, array $line, array $common): void
    {
        $quantity = (float) $line['quantity'];
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('A transfer quantity must be more than 0.');
        }

        $base = $common + ['sku' => $line['sku'], 'lot' => (string) ($line['lot'] ?? ''), 'expiry' => $line['expiry'] ?? null];
        $this->move($base + $from + ['quantity' => -$quantity]);
        $this->move($base + $to + ['quantity' => $quantity]);
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}
