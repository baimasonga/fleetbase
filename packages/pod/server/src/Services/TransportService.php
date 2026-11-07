<?php

namespace Fleetbase\Pod\Services;

use Fleetbase\Pod\Models\Discrepancy;
use Fleetbase\Pod\Models\LegLine;
use Fleetbase\Pod\Models\Programme;
use Fleetbase\Pod\Models\ProgrammeItem;
use Fleetbase\Pod\Models\Site;
use Fleetbase\Pod\Models\TransportLeg;
use Fleetbase\Pod\Support\Seal;
use Fleetbase\Pod\Support\Transport\ReceiptCheck;
use Fleetbase\Pod\Support\Transport\SealCheck;
use Illuminate\Support\Facades\DB;

/**
 * Truck trips between sites (AGRI-INPUTS.md §4):
 *
 *   create   draft trip: from, to, transporter, vehicle, waybill
 *   load     count per item and lot, seal numbers, pesticides kept apart;
 *            stock leaves the origin
 *   depart / arrive
 *   receive  count good and damaged per item and lot, check the seals;
 *            good stock enters the destination; every difference becomes a
 *            discrepancy (shortages charged to the transporter while open)
 *   close    every discrepancy explained and given a resolution, seal problems
 *            explained; the trip is sealed with a hash
 *
 * The person who loaded a trip can't receive or close it.
 */
class TransportService
{
    public function __construct(private StockLedger $stock)
    {
    }

    public function create(Programme $programme, array $data, ?string $userUuid = null): TransportLeg
    {
        $origin      = $this->site($programme->company_uuid, $data['origin'] ?? null, 'origin');
        $destination = $this->site($programme->company_uuid, $data['destination'] ?? null, 'destination');
        if ($origin->uuid === $destination->uuid) {
            throw new \InvalidArgumentException('The origin and destination must be different sites.');
        }

        $kind = $data['transporter_kind'] ?? 'staff';
        if (!in_array($kind, ['staff', 'hired'], true)) {
            throw new \InvalidArgumentException('The transporter must be "staff" or "hired".');
        }
        if ($kind === 'hired' && empty($data['transporter_name'])) {
            throw new \InvalidArgumentException('Give the hired transporter\'s name.');
        }

        return TransportLeg::create([
            'company_uuid'          => $programme->company_uuid,
            'programme_uuid'        => $programme->uuid,
            'origin_site_uuid'      => $origin->uuid,
            'destination_site_uuid' => $destination->uuid,
            'status'                => TransportLeg::DRAFT,
            'transporter_kind'      => $kind,
            'transporter_name'      => $data['transporter_name'] ?? null,
            'transporter_phone'     => $data['transporter_phone'] ?? null,
            'transporter_user_uuid' => $data['transporter_user_uuid'] ?? null,
            'vehicle_plate'         => isset($data['vehicle_plate']) ? strtoupper(trim($data['vehicle_plate'])) : null,
            'waybill_no'            => $data['waybill_no'] ?? null,
            'expected_duration_min' => $data['expected_duration_min'] ?? null,
            'order_uuid'            => $data['order_uuid'] ?? null,
            'vendor_uuid'           => $data['vendor_uuid'] ?? null,
            'driver_uuid'           => $data['driver_uuid'] ?? null,
            'vehicle_uuid'          => $data['vehicle_uuid'] ?? null,
            'notes'                 => $data['notes'] ?? null,
            'flags'                 => [],
        ]);
    }

    /**
     * @param list<array{sku: string, lot?: string, expiry?: ?string, quantity: float}> $lines
     * @param list<string>                                                              $seals
     */
    public function load(TransportLeg $leg, array $lines, array $seals, ?bool $restrictedSegregated, ?string $userUuid): TransportLeg
    {
        $this->expectStatus($leg, [TransportLeg::DRAFT], 'loaded');
        if (!$lines) {
            throw new \InvalidArgumentException('Count at least one line onto the truck.');
        }
        if (empty($leg->waybill_no)) {
            throw new \InvalidArgumentException('Record the waybill number before loading.');
        }

        $items = ProgrammeItem::where('programme_uuid', $leg->programme_uuid)->get()->keyBy('sku');
        $keys  = [];
        foreach ($lines as $i => $line) {
            $sku = $line['sku'] ?? '';
            if (!$items->has($sku)) {
                throw new \InvalidArgumentException('Line ' . ($i + 1) . ": item \"{$sku}\" is not in the programme's item list.");
            }
            if (!is_numeric($line['quantity'] ?? null) || $line['quantity'] <= 0) {
                throw new \InvalidArgumentException('Line ' . ($i + 1) . ': the quantity must be more than 0.');
            }
            $key = $sku . '|' . ($line['lot'] ?? '');
            if (isset($keys[$key])) {
                throw new \InvalidArgumentException('Line ' . ($i + 1) . ": {$sku} lot " . ($line['lot'] ?? '-') . ' is listed twice.');
            }
            $keys[$key] = true;
        }

        // Note: Eloquent's Collection::only() filters by primary key, not by array key.
        $skus          = array_column($lines, 'sku');
        $carried       = $items->filter(fn ($item) => in_array($item->sku, $skus, true));
        $hasRestricted = $carried->contains(fn ($item) => $item->restricted);
        $hasOther      = $carried->contains(fn ($item) => !$item->restricted);
        if ($hasRestricted && $hasOther && $restrictedSegregated !== true) {
            throw new \InvalidArgumentException('Pesticides and veterinary drugs are on this truck with other inputs. Confirm they are kept in a separate compartment or clearly apart.');
        }

        $seals = array_values(array_filter(array_map(fn ($s) => trim((string) $s), $seals), fn ($s) => $s !== ''));
        if (count($seals) !== count(array_unique(array_map('strtoupper', $seals)))) {
            throw new \InvalidArgumentException('A seal number is listed twice.');
        }

        DB::transaction(function () use ($leg, $lines, $seals, $hasRestricted, $restrictedSegregated, $userUuid) {
            foreach ($lines as $line) {
                LegLine::create([
                    'leg_uuid'        => $leg->uuid,
                    'sku'             => $line['sku'],
                    'lot'             => (string) ($line['lot'] ?? ''),
                    'expiry'          => $line['expiry'] ?? null,
                    'loaded_quantity' => (float) $line['quantity'],
                ]);
                $this->stock->move([
                    'company_uuid'   => $leg->company_uuid,
                    'programme_uuid' => $leg->programme_uuid,
                    'holder_type'    => 'site',
                    'holder_uuid'    => $leg->origin_site_uuid,
                    'sku'            => $line['sku'],
                    'lot'            => (string) ($line['lot'] ?? ''),
                    'expiry'         => $line['expiry'] ?? null,
                    'quantity'       => -(float) $line['quantity'],
                    'reason'         => 'dispatched',
                    'reference_type' => 'leg',
                    'reference_uuid' => $leg->uuid,
                    'user_uuid'      => $userUuid,
                ]);
            }

            $leg->update([
                'status'                => TransportLeg::LOADED,
                'seals_loaded'          => $seals,
                'restricted_segregated' => $hasRestricted ? (bool) $restrictedSegregated : null,
                'loaded_at'             => now(),
                'loaded_by_uuid'        => $userUuid,
            ]);
        });

        return $leg->refresh();
    }

    public function depart(TransportLeg $leg): TransportLeg
    {
        $this->expectStatus($leg, [TransportLeg::LOADED], 'departed');
        $leg->update(['status' => TransportLeg::IN_TRANSIT, 'departed_at' => now()]);

        return $leg;
    }

    public function arrive(TransportLeg $leg): TransportLeg
    {
        $this->expectStatus($leg, [TransportLeg::IN_TRANSIT], 'arrived');
        $leg->update(['status' => TransportLeg::ARRIVED, 'arrived_at' => now(), 'flags' => $this->transitFlags($leg, now())]);

        return $leg;
    }

    /**
     * @param list<array{sku: string, lot?: string, good: float, damaged?: float, note?: string}> $received
     * @param list<array{number: string, intact: bool}>                                          $seals
     */
    public function receive(TransportLeg $leg, array $received, array $seals, ?string $userUuid): TransportLeg
    {
        $this->expectStatus($leg, [TransportLeg::IN_TRANSIT, TransportLeg::ARRIVED], 'received');
        $this->expectDifferentPerson($leg, $userUuid, 'receive');
        if (!$received) {
            throw new \InvalidArgumentException('Count what arrived: give at least one line (use 0 for an item that did not arrive).');
        }

        $lines   = $leg->lines()->get();
        $compare = ReceiptCheck::compare(
            $lines->map(fn (LegLine $l) => ['sku' => $l->sku, 'lot' => $l->lot, 'loaded' => $l->loaded_quantity])->all(),
            array_map(fn ($r) => ['sku' => $r['sku'] ?? '', 'lot' => (string) ($r['lot'] ?? ''), 'good' => (float) ($r['good'] ?? 0), 'damaged' => (float) ($r['damaged'] ?? 0)], $received)
        );
        $sealResult = SealCheck::compare($leg->seals_loaded ?? [], $seals);
        $notes      = [];
        foreach ($received as $r) {
            if (!empty($r['note'])) {
                $notes[($r['sku'] ?? '') . '|' . ($r['lot'] ?? '')] = $r['note'];
            }
        }

        DB::transaction(function () use ($leg, $lines, $compare, $sealResult, $seals, $notes, $userUuid) {
            $now   = now();
            $flags = $leg->arrived_at ? ($leg->flags ?? []) : $this->transitFlags($leg, $now);

            foreach ($compare as $line) {
                $key   = $line['sku'] . '|' . $line['lot'];
                $model = $lines->first(fn (LegLine $l) => $l->sku === $line['sku'] && $l->lot === $line['lot']);
                if ($model) {
                    $model->update(['received_good' => $line['good'], 'received_damaged' => $line['damaged'], 'condition_note' => $notes[$key] ?? null]);
                } else {
                    LegLine::create(['leg_uuid' => $leg->uuid, 'sku' => $line['sku'], 'lot' => $line['lot'], 'loaded_quantity' => 0, 'received_good' => $line['good'], 'received_damaged' => $line['damaged'], 'condition_note' => $notes[$key] ?? null]);
                }

                if ($line['good'] > 0) {
                    $this->stock->move([
                        'company_uuid'   => $leg->company_uuid,
                        'programme_uuid' => $leg->programme_uuid,
                        'holder_type'    => 'site',
                        'holder_uuid'    => $leg->destination_site_uuid,
                        'sku'            => $line['sku'],
                        'lot'            => $line['lot'],
                        'expiry'         => $model?->expiry,
                        'quantity'       => $line['good'],
                        'reason'         => 'received',
                        'reference_type' => 'leg',
                        'reference_uuid' => $leg->uuid,
                        'user_uuid'      => $userUuid,
                    ]);
                }

                // Charged to a hired transporter while it's investigated (AGRI-INPUTS.md §4.1).
                $liable = $leg->transporter_kind === 'hired' ? 'transporter' : 'unknown';
                if ($line['damaged'] > 0) {
                    $this->discrepancy($leg, $line['sku'], $line['lot'], $line['damaged'], 'damaged', $liable, $notes[$key] ?? null, $userUuid);
                }
                if ($line['short'] != 0) {
                    $this->discrepancy($leg, $line['sku'], $line['lot'], $line['short'], $line['reason'], $line['short'] > 0 ? $liable : 'unknown', null, $userUuid);
                }
            }

            if (!in_array($sealResult['status'], [SealCheck::INTACT, SealCheck::NONE], true)) {
                $flags[] = 'seal_' . $sealResult['status'];
            }

            $leg->update([
                'status'           => TransportLeg::RECEIVED,
                'arrived_at'       => $leg->arrived_at ?? $now,
                'received_at'      => $now,
                'received_by_uuid' => $userUuid,
                'seals_received'   => $seals,
                'seal_status'      => $sealResult['status'],
                'flags'            => array_values(array_unique($flags)),
            ]);
        });

        return $leg->refresh();
    }

    /**
     * Explain and resolve a discrepancy on a trip or at an event.
     */
    public function resolveDiscrepancy(Discrepancy $discrepancy, array $data, ?string $userUuid): Discrepancy
    {
        if ($discrepancy->status === 'resolved') {
            throw new \LogicException('This discrepancy is already resolved.');
        }
        if (empty(trim((string) ($data['notes'] ?? '')))) {
            throw new \InvalidArgumentException('Explain what happened.');
        }
        if (!in_array($data['resolution'] ?? null, Discrepancy::RESOLUTIONS, true)) {
            throw new \InvalidArgumentException('The resolution must be one of: ' . implode(', ', Discrepancy::RESOLUTIONS) . '.');
        }
        foreach (['reason' => Discrepancy::REASONS, 'liable_party' => Discrepancy::LIABLE] as $field => $allowed) {
            if (isset($data[$field]) && !in_array($data[$field], $allowed, true)) {
                throw new \InvalidArgumentException("Unknown {$field} \"{$data[$field]}\".");
            }
        }

        $discrepancy->update([
            'reason'           => $data['reason'] ?? $discrepancy->reason,
            'liable_party'     => $data['liable_party'] ?? $discrepancy->liable_party,
            'notes'            => trim((string) $data['notes']),
            'evidence'         => $data['evidence'] ?? $discrepancy->evidence,
            'resolution'       => $data['resolution'],
            'status'           => 'resolved',
            'resolved_by_uuid' => $userUuid,
            'resolved_at'      => now(),
        ]);

        return $discrepancy;
    }

    public function close(TransportLeg $leg, ?string $sealExplanation, ?string $userUuid): TransportLeg
    {
        $this->expectStatus($leg, [TransportLeg::RECEIVED], 'closed');
        $this->expectDifferentPerson($leg, $userUuid, 'close');

        $open = $leg->discrepancies()->where('status', '!=', 'resolved')->count();
        if ($open > 0) {
            throw new \LogicException("{$open} discrepancies on this trip still need an explanation and a resolution.");
        }
        $sealProblem = !in_array($leg->seal_status, [SealCheck::INTACT, SealCheck::NONE, null], true);
        if ($sealProblem && trim((string) $sealExplanation) === '') {
            throw new \LogicException("The seals were {$leg->seal_status} on arrival. Explain what happened before closing the trip.");
        }

        $leg->load(['lines', 'discrepancies', 'origin', 'destination']);
        $notes = trim((string) $leg->notes . ($sealProblem ? "\nSeals: " . trim((string) $sealExplanation) : ''));
        $hash  = Seal::hash([
            'leg'           => $leg->public_id,
            'from'          => $leg->origin->public_id,
            'to'            => $leg->destination->public_id,
            'transporter'   => [$leg->transporter_kind, $leg->transporter_name, $leg->vehicle_plate, $leg->waybill_no],
            'seals'         => [$leg->seals_loaded, $leg->seals_received, $leg->seal_status],
            'lines'         => $leg->lines->map(fn ($l) => [$l->sku, $l->lot, $l->loaded_quantity, $l->received_good, $l->received_damaged])->all(),
            'discrepancies' => $leg->discrepancies->map(fn ($d) => [$d->public_id, $d->sku, $d->lot, $d->quantity, $d->reason, $d->resolution])->all(),
            'times'         => [(string) $leg->loaded_at, (string) $leg->received_at],
        ]);

        $leg->update(['status' => TransportLeg::CLOSED, 'closed_at' => now(), 'closed_by_uuid' => $userUuid, 'notes' => $notes ?: null, 'seal_hash' => $hash]);

        return $leg->refresh();
    }

    /**
     * Cancel a trip before it leaves. Loaded stock goes back to the origin.
     */
    public function cancel(TransportLeg $leg, ?string $userUuid): TransportLeg
    {
        $this->expectStatus($leg, [TransportLeg::DRAFT, TransportLeg::LOADED], 'cancelled');

        DB::transaction(function () use ($leg, $userUuid) {
            if ($leg->status === TransportLeg::LOADED) {
                foreach ($leg->lines()->get() as $line) {
                    $this->stock->move([
                        'company_uuid'   => $leg->company_uuid,
                        'programme_uuid' => $leg->programme_uuid,
                        'holder_type'    => 'site',
                        'holder_uuid'    => $leg->origin_site_uuid,
                        'sku'            => $line->sku,
                        'lot'            => $line->lot,
                        'expiry'         => $line->expiry,
                        'quantity'       => $line->loaded_quantity,
                        'reason'         => 'adjustment',
                        'reference_type' => 'leg',
                        'reference_uuid' => $leg->uuid,
                        'user_uuid'      => $userUuid,
                        'note'           => 'Trip cancelled before departure',
                    ]);
                }
            }
            $leg->update(['status' => TransportLeg::CANCELLED]);
        });

        return $leg->refresh();
    }

    private function discrepancy(TransportLeg $leg, string $sku, string $lot, float $quantity, string $reason, string $liable, ?string $notes, ?string $userUuid): void
    {
        Discrepancy::create([
            'company_uuid'    => $leg->company_uuid,
            'programme_uuid'  => $leg->programme_uuid,
            'subject_type'    => 'leg',
            'subject_uuid'    => $leg->uuid,
            'sku'             => $sku,
            'lot'             => $lot,
            'quantity'        => $quantity,
            'reason'          => $reason,
            'liable_party'    => $liable,
            'notes'           => $notes,
            'status'          => 'open',
            'created_by_uuid' => $userUuid,
        ]);
    }

    /**
     * REPORTING-AUDIT.md AR-23: a trip that took more than twice the expected time.
     */
    private function transitFlags(TransportLeg $leg, \DateTimeInterface $arrived): array
    {
        $flags = $leg->flags ?? [];
        if ($leg->expected_duration_min && $leg->departed_at) {
            $minutes = ($arrived->getTimestamp() - $leg->departed_at->getTimestamp()) / 60;
            if ($minutes > 2 * $leg->expected_duration_min) {
                $flags[] = 'transit_slow';
            }
        }

        return array_values(array_unique($flags));
    }

    private function expectStatus(TransportLeg $leg, array $allowed, string $action): void
    {
        if (!in_array($leg->status, $allowed, true)) {
            throw new \LogicException("A trip that is {$leg->status} can't be {$action}.");
        }
    }

    private function expectDifferentPerson(TransportLeg $leg, ?string $userUuid, string $action): void
    {
        if ($userUuid !== null && $leg->loaded_by_uuid !== null && $userUuid === $leg->loaded_by_uuid) {
            throw new \LogicException("The person who loaded this trip can't {$action} it. Another staff member must do it.");
        }
    }

    private function site(string $companyUuid, ?string $id, string $label): Site
    {
        $site = $id ? Site::where('company_uuid', $companyUuid)->where(fn ($q) => $q->where('public_id', $id)->orWhere('uuid', $id))->first() : null;
        if (!$site) {
            throw new \InvalidArgumentException("The {$label} site wasn't found.");
        }
        if (!$site->active) {
            throw new \InvalidArgumentException("The {$label} site is not active.");
        }

        return $site;
    }
}
