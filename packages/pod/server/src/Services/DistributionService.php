<?php

namespace Fleetbase\Pod\Services;

use Fleetbase\Pod\Models\Beneficiary;
use Fleetbase\Pod\Models\Discrepancy;
use Fleetbase\Pod\Models\DistributionEvent;
use Fleetbase\Pod\Models\Entitlement;
use Fleetbase\Pod\Models\Handover;
use Fleetbase\Pod\Models\Programme;
use Fleetbase\Pod\Models\ProgrammeItem;
use Fleetbase\Pod\Models\Site;
use Fleetbase\Pod\Support\Cards\ProgrammeCard;
use Fleetbase\Pod\Support\Distribution\AgeBands;
use Fleetbase\Pod\Support\Distribution\HandoverPolicy;
use Fleetbase\Pod\Support\Geo\Distance;
use Fleetbase\Pod\Support\Policy\IdentityLevels;
use Fleetbase\Pod\Support\Policy\RequirementTree;
use Fleetbase\Pod\Support\Reconciliation\StockReconciler;
use Fleetbase\Pod\Support\Seal;
use Illuminate\Support\Facades\DB;

/**
 * Distribution days and door-to-door routes (AGRI-INPUTS.md §3, §7.2, §8, §11).
 *
 *   create   a day at a distribution point (kind "point") or a route from a vehicle (kind "route")
 *   assign   farmers' open entitlements to it, by area, village or list
 *   open     witnessed opening count; stock moves from the site to the event
 *   handover card or ID -> identity level -> lines within the remaining
 *            entitlement -> lot in stock and not expired or recalled -> proof
 *            policy -> sealed record; stock and entitlements updated
 *   fail     a failed door-to-door attempt with a reason
 *   reassign move entitlements to another event (e.g. after failed attempts)
 *   close    witnessed closing count, reconciled per item and lot; the rest
 *            goes back to the site; totals by sex and age band; sealed
 */
class DistributionService
{
    public const FAILURE_REASONS = ['farmer_absent', 'household_moved', 'farmer_deceased', 'refused', 'wrong_village', 'road_inaccessible', 'security_issue', 'already_received', 'other'];

    public const WITNESS_ROLES = ['chief', 'section_chief', 'group_leader', 'extension_officer', 'councillor', 'community_leader', 'other'];

    public function __construct(
        private StockLedger $stock,
        private CardService $cards,
        private AdminAreaRepository $areas,
        private \Fleetbase\Pod\Support\Countries\CountryProfiles $countries,
    ) {
    }

    public function create(Programme $programme, array $data): DistributionEvent
    {
        $kind = $data['kind'] ?? 'point';
        if (!in_array($kind, ['point', 'route'], true)) {
            throw new \InvalidArgumentException('The kind must be "point" or "route".');
        }

        $site = Site::where('company_uuid', $programme->company_uuid)->where(fn ($q) => $q->where('public_id', $data['site'] ?? '')->orWhere('uuid', $data['site'] ?? ''))->first();
        if (!$site || !$site->active) {
            throw new \InvalidArgumentException('The site wasn\'t found or is not active.');
        }
        $allowed = $kind === 'point' ? ['distribution_point', 'district_store'] : ['vehicle'];
        if (!in_array($site->type, $allowed, true)) {
            throw new \InvalidArgumentException($kind === 'point' ? 'A distribution day must be at a distribution point or district store.' : 'A door-to-door route must start from a vehicle site.');
        }

        return DistributionEvent::create([
            'company_uuid'   => $programme->company_uuid,
            'programme_uuid' => $programme->uuid,
            'kind'           => $kind,
            'site_uuid'      => $site->uuid,
            'name'           => trim((string) ($data['name'] ?? '')) ?: $site->name . ($data['scheduled_date'] ?? ''),
            'scheduled_date' => $data['scheduled_date'] ?? null,
            'status'         => DistributionEvent::PLANNED,
        ]);
    }

    /**
     * Assign farmers' open entitlements to the event.
     *
     * @return array{assigned: int, beneficiaries: int, elsewhere: int}
     */
    public function assign(DistributionEvent $event, array $filters): array
    {
        $this->expectStatus($event, [DistributionEvent::PLANNED, DistributionEvent::OPEN], 'given farmers');
        if (!array_filter(array_intersect_key($filters, array_flip(['admin_2', 'admin_3', 'admin_4', 'village', 'beneficiaries'])))) {
            throw new \InvalidArgumentException('Choose the farmers: an area, a village or a list.');
        }

        $model = $event->kind === 'route' ? 'door_to_door' : 'distribution_point';

        $beneficiaries = Beneficiary::where('programme_uuid', $event->programme_uuid)->where('status', 'active');
        foreach (['admin_2', 'admin_3', 'admin_4', 'village'] as $field) {
            if (!empty($filters[$field])) {
                $beneficiaries->where($field, $filters[$field]);
            }
        }
        if (!empty($filters['beneficiaries'])) {
            $beneficiaries->where(fn ($q) => $q->whereIn('public_id', $filters['beneficiaries'])->orWhereIn('uuid', $filters['beneficiaries']));
        }
        $ids = $beneficiaries->pluck('uuid');

        $open = Entitlement::where('programme_uuid', $event->programme_uuid)
            ->whereIn('beneficiary_uuid', $ids)
            ->whereIn('status', [Entitlement::OPEN, Entitlement::PARTIALLY_COLLECTED]);

        $activeEvents = DistributionEvent::where('programme_uuid', $event->programme_uuid)->whereIn('status', [DistributionEvent::PLANNED, DistributionEvent::OPEN])->where('uuid', '!=', $event->uuid)->pluck('uuid');
        $elsewhere    = (clone $open)->whereIn('distribution_event_uuid', $activeEvents)->count();

        $toAssign = (clone $open)->where(fn ($q) => $q->whereNull('distribution_event_uuid')->orWhereNotIn('distribution_event_uuid', $activeEvents));
        $count    = (clone $toAssign)->count();
        $people   = (clone $toAssign)->distinct()->count('beneficiary_uuid');
        $toAssign->update(['distribution_event_uuid' => $event->uuid, 'delivery_model' => $model]);

        return ['assigned' => $count, 'beneficiaries' => $people, 'elsewhere' => $elsewhere];
    }

    /**
     * @param list<array{sku: string, lot?: string, quantity: float}> $stock     counted at opening, taken from the site
     * @param list<array{name: string, role: string}>                $witnesses
     */
    public function open(DistributionEvent $event, array $stock, array $witnesses, ?string $userUuid, ?string $clientId = null, ?\DateTimeInterface $at = null): DistributionEvent
    {
        $this->expectStatus($event, [DistributionEvent::PLANNED], 'opened');
        $witnesses = $this->witnesses($witnesses);
        if (!$stock) {
            throw new \InvalidArgumentException('Count the stock at opening.');
        }

        DB::transaction(function () use ($event, $stock, $witnesses, $userUuid, $clientId, $at) {
            $opening = [];
            foreach ($stock as $line) {
                $lot      = (string) ($line['lot'] ?? '');
                $quantity = (float) ($line['quantity'] ?? 0);
                if ($quantity <= 0) {
                    throw new \InvalidArgumentException("The opening quantity of {$line['sku']} must be more than 0.");
                }
                $expiry = $this->lotExpiry('site', $event->site_uuid, $line['sku'], $lot);
                $this->stock->transfer(
                    ['holder_type' => 'site', 'holder_uuid' => $event->site_uuid, 'reason' => 'to_event'],
                    ['holder_type' => 'event', 'holder_uuid' => $event->uuid, 'reason' => 'to_event'],
                    ['sku' => $line['sku'], 'lot' => $lot, 'quantity' => $quantity, 'expiry' => $expiry],
                    ['company_uuid' => $event->company_uuid, 'programme_uuid' => $event->programme_uuid, 'reference_type' => 'event', 'reference_uuid' => $event->uuid, 'user_uuid' => $userUuid]
                );
                $opening[] = ['sku' => $line['sku'], 'lot' => $lot, 'quantity' => $quantity, 'expiry' => $expiry];
            }

            $event->update([
                'status'         => DistributionEvent::OPEN,
                // An opening recorded offline is dated when it happened, not when it synced.
                'opened_at'      => $at && $at <= now() ? $at : now(),
                'opened_by_uuid' => $userUuid,
                'open_client_id' => $clientId,
                'opening_stock'  => $opening,
                'witnesses'      => ['open' => $witnesses],
            ]);
        });

        return $event->refresh();
    }

    /**
     * Record a handover to one farmer.
     *
     * @return array{handover: Handover, replayed: bool, remaining: list<array>}
     */
    public function handover(DistributionEvent $event, array $input, ?string $userUuid, array $syncFlags = []): array
    {
        // A retry of a handover already recorded (same client id) returns the original.
        if (!empty($input['client_handover_id'])) {
            $existing = Handover::where('company_uuid', $event->company_uuid)->where('client_handover_id', $input['client_handover_id'])->first();
            if ($existing) {
                return ['handover' => $existing, 'replayed' => true, 'remaining' => $this->remaining($existing->beneficiary_uuid, $event)];
            }
        }

        $this->expectStatus($event, [DistributionEvent::OPEN], 'used for handovers');
        $programme = $event->programme;

        [$beneficiary, $cardValid] = $this->identify($programme, $input);

        if ($beneficiary->status !== 'active') {
            throw new HandoverRefused('not_active', "This farmer's record is {$beneficiary->status}.");
        }
        if (!$beneficiary->isVerified() && !$programme->allowsUnverifiedEntitlements()) {
            throw new HandoverRefused('not_verified', 'This farmer has not been verified.');
        }

        $entitlements = Entitlement::where('beneficiary_uuid', $beneficiary->uuid)->where('programme_uuid', $programme->uuid)->get();
        $here         = $entitlements->where('distribution_event_uuid', $event->uuid)->whereIn('status', [Entitlement::OPEN, Entitlement::PARTIALLY_COLLECTED])->keyBy('sku');
        if ($here->isEmpty()) {
            if ($entitlements->whereIn('status', [Entitlement::OPEN, Entitlement::PARTIALLY_COLLECTED])->isNotEmpty()) {
                throw new HandoverRefused('assigned_elsewhere', 'This farmer is assigned to another distribution. Send them there, or reassign them first.');
            }
            if ($entitlements->where('status', Entitlement::COLLECTED)->isNotEmpty()) {
                throw new HandoverRefused('already_collected', 'This farmer has already collected everything they are entitled to.');
            }
            throw new HandoverRefused('no_entitlement', 'This farmer has no entitlement in this programme.');
        }

        // Identity (AGRI-INPUTS.md §7.2).
        $checks = $input['identity'] ?? [];
        $idLast4Given   = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($checks['id_last4'] ?? '')));
        $govIdMatched   = $idLast4Given !== '' && $beneficiary->id_last4 !== null && $idLast4Given === strtoupper($beneficiary->id_last4);
        $level          = IdentityLevels::determine($cardValid, (bool) ($checks['face_matched'] ?? false), $govIdMatched, (bool) ($checks['photo_taken'] ?? false), (bool) ($checks['witness_confirmed'] ?? false));
        if ($level === null) {
            throw new HandoverRefused('identity_insufficient', 'The farmer\'s identity is not confirmed: scan their card and match their face, or take a photo with a witness confirming who they are.');
        }

        $receivedBy = $input['received_by'] ?? 'self';
        if (!in_array($receivedBy, ['self', 'household_member', 'proxy'], true)) {
            throw new \InvalidArgumentException('received_by must be self, household_member or proxy.');
        }
        if ($receivedBy !== 'self' && trim((string) ($input['recipient_name'] ?? '')) === '') {
            throw new \InvalidArgumentException('Give the name of the person collecting for the farmer.');
        }

        // Lines (§8): within the remaining entitlement, from stock that is in date and not recalled.
        $items   = ProgrammeItem::where('programme_uuid', $programme->uuid)->get()->keyBy('sku');
        $lines   = $this->lines($input['lines'] ?? [], $here, $items, $beneficiary, $event, $programme);
        $restricted = collect($lines)->contains(fn ($line) => $items[$line['sku']]->restricted);

        // Proof (§8).
        $policy   = HandoverPolicy::build($programme->rules['handover_policy'] ?? null, $receivedBy === 'proxy', $level, $restricted);
        $evidence = array_values(array_filter($input['evidence'] ?? [], fn ($e) => is_array($e) && isset($e['type'])));
        $evidence[] = ['type' => 'beneficiary_id', 'level' => $level, 'method' => $cardValid ? 'card_qr' : 'witness'];
        foreach ($lines as $line) {
            $evidence[] = ['type' => 'line_quantities', 'line' => $line['sku'], 'quantity' => $line['quantity']];
            if ($line['lot'] !== '') {
                $evidence[] = ['type' => 'lot_scan', 'line' => $line['sku'], 'lot' => $line['lot']];
            }
        }
        $context = ['lines' => array_map(fn ($line) => ['id' => $line['sku'], 'entitled' => $line['remaining_before']], $lines)];
        $result  = (new RequirementTree())->evaluate($policy, $evidence, $context);
        if (!$result->satisfied) {
            throw new HandoverRefused('proof_missing', 'Some proof required for this handover is missing.', ['missing' => $result->missing]);
        }

        // Location and flags.
        [$distance, $flags] = $this->locationCheck($event, $beneficiary, $input);
        if ($level === IdentityLevels::L1) {
            $flags[] = 'identity_l1';
        }
        if ($receivedBy === 'proxy') {
            $flags[] = 'proxy';
        }
        $flags = array_merge($flags, $syncFlags);

        $handover = DB::transaction(function () use ($event, $programme, $beneficiary, $input, $userUuid, $lines, $here, $level, $checks, $cardValid, $govIdMatched, $receivedBy, $evidence, $distance, $flags) {
            $complete = true;
            foreach ($here as $sku => $entitlement) {
                $line      = collect($lines)->firstWhere('sku', $sku);
                $collected = $entitlement->collected_quantity + ($line['quantity'] ?? 0);
                if ($collected + 0.0005 < $entitlement->quantity) {
                    $complete = false;
                }
            }

            $handover = Handover::create([
                'company_uuid'       => $event->company_uuid,
                'programme_uuid'     => $programme->uuid,
                'event_uuid'         => $event->uuid,
                'beneficiary_uuid'   => $beneficiary->uuid,
                'client_handover_id' => $input['client_handover_id'] ?? null,
                'outcome'            => $complete ? 'delivered' : 'partial',
                'received_by'        => $receivedBy,
                'recipient_name'     => $receivedBy === 'self' ? $beneficiary->name : trim((string) $input['recipient_name']),
                'identity_level'     => $level,
                'identity_checks'    => ['card_valid' => $cardValid, 'face_matched' => (bool) ($checks['face_matched'] ?? false), 'government_id_matched' => $govIdMatched, 'photo_taken' => (bool) ($checks['photo_taken'] ?? false), 'witness_confirmed' => (bool) ($checks['witness_confirmed'] ?? false)],
                'lines'              => array_map(fn ($l) => ['sku' => $l['sku'], 'lot' => $l['lot'], 'quantity' => $l['quantity'], 'unit' => $l['unit']], $lines),
                'evidence'           => array_values(array_filter($evidence, fn ($e) => !in_array($e['type'], ['beneficiary_id', 'line_quantities', 'lot_scan'], true))),
                'flags'              => array_values(array_unique($flags)),
                'latitude'           => $input['latitude'] ?? null,
                'longitude'          => $input['longitude'] ?? null,
                'distance_m'         => $distance,
                'captured_at'        => $input['captured_at'] ?? now(),
                'attempt_number'     => (int) $here->max('attempts') + 1,
                'officer_uuid'       => $userUuid,
                'status'             => 'sealed',
            ]);

            foreach ($lines as $line) {
                $this->stock->move([
                    'company_uuid'   => $event->company_uuid,
                    'programme_uuid' => $programme->uuid,
                    'holder_type'    => 'event',
                    'holder_uuid'    => $event->uuid,
                    'sku'            => $line['sku'],
                    'lot'            => $line['lot'],
                    'quantity'       => -$line['quantity'],
                    'reason'         => 'handed_over',
                    'reference_type' => 'handover',
                    'reference_uuid' => $handover->uuid,
                    'user_uuid'      => $userUuid,
                ]);

                $entitlement = $here[$line['sku']];
                $collected   = round($entitlement->collected_quantity + $line['quantity'], 3);
                $entitlement->update([
                    'collected_quantity' => $collected,
                    'status'             => $collected + 0.0005 >= $entitlement->quantity ? Entitlement::COLLECTED : Entitlement::PARTIALLY_COLLECTED,
                    'attempts'           => $entitlement->attempts + 1,
                ]);
            }

            // The seal covers the stored record; it's written in the same transaction.
            $handover->seal_hash = Seal::hash($this->sealable($handover, $event, $beneficiary));
            $handover->saveQuietly();

            return $handover;
        });

        return ['handover' => $handover->refresh(), 'replayed' => false, 'remaining' => $this->remaining($beneficiary->uuid, $event)];
    }

    /**
     * A failed door-to-door attempt (AGRI-INPUTS.md §3.2).
     *
     * @return array{handover: Handover, attempts: int, max_attempts: int, needs_reassignment: bool, registry_review: bool}
     */
    public function fail(DistributionEvent $event, array $input, ?string $userUuid, array $syncFlags = []): array
    {
        if (!empty($input['client_handover_id'])) {
            $existing = Handover::where('company_uuid', $event->company_uuid)->where('client_handover_id', $input['client_handover_id'])->first();
            if ($existing) {
                return ['handover' => $existing, 'attempts' => $existing->attempt_number, 'max_attempts' => $this->maxAttempts($event->programme), 'needs_reassignment' => false, 'registry_review' => false];
            }
        }

        $this->expectStatus($event, [DistributionEvent::OPEN], 'used for attempts');
        $reason = $input['reason'] ?? null;
        if (!in_array($reason, self::FAILURE_REASONS, true)) {
            throw new \InvalidArgumentException('The reason must be one of: ' . implode(', ', self::FAILURE_REASONS) . '.');
        }

        $beneficiary = $this->beneficiary($event->programme, (string) ($input['beneficiary'] ?? ''));
        $here        = Entitlement::where('beneficiary_uuid', $beneficiary->uuid)->where('distribution_event_uuid', $event->uuid)->whereIn('status', [Entitlement::OPEN, Entitlement::PARTIALLY_COLLECTED])->get();
        if ($here->isEmpty()) {
            throw new HandoverRefused('no_entitlement', 'This farmer has nothing assigned to this route.');
        }

        [$distance, $flags] = $this->locationCheck($event, $beneficiary, $input);
        $flags              = array_values(array_unique(array_merge($flags, $syncFlags)));
        $attempts           = (int) $here->max('attempts') + 1;
        $max                = $this->maxAttempts($event->programme);
        $registry           = in_array($reason, ['farmer_deceased', 'household_moved'], true);

        $handover = DB::transaction(function () use ($event, $beneficiary, $input, $reason, $here, $attempts, $distance, $flags, $userUuid, $registry) {
            foreach ($here as $entitlement) {
                $entitlement->update(['attempts' => $entitlement->attempts + 1]);
            }
            if ($registry) {
                $beneficiary->update(['meta' => array_merge($beneficiary->meta ?? [], ['registry_review' => $reason])]);
            }

            $handover = Handover::create([
                'company_uuid'       => $event->company_uuid,
                'programme_uuid'     => $event->programme_uuid,
                'event_uuid'         => $event->uuid,
                'beneficiary_uuid'   => $beneficiary->uuid,
                'client_handover_id' => $input['client_handover_id'] ?? null,
                'outcome'            => 'failed',
                'failure_reason'     => $reason,
                'evidence'           => array_values(array_filter($input['evidence'] ?? [], fn ($e) => is_array($e) && isset($e['type']))),
                'flags'              => $flags,
                'latitude'           => $input['latitude'] ?? null,
                'longitude'          => $input['longitude'] ?? null,
                'distance_m'         => $distance,
                'captured_at'        => $input['captured_at'] ?? now(),
                'attempt_number'     => $attempts,
                'officer_uuid'       => $userUuid,
                'status'             => 'sealed',
            ]);
            $handover->seal_hash = Seal::hash($this->sealable($handover, $event, $beneficiary));
            $handover->saveQuietly();

            return $handover;
        });

        return [
            'handover'           => $handover->refresh(),
            'attempts'           => $attempts,
            'max_attempts'       => $max,
            // Deceased or moved: a registry question, not another visit.
            'needs_reassignment' => !$registry && $attempts >= $max,
            'registry_review'    => $registry,
        ];
    }

    /**
     * Move a farmer's open entitlements from this event to another (e.g. from a
     * door-to-door route to the nearest distribution point).
     */
    public function reassign(DistributionEvent $from, string $beneficiaryId, DistributionEvent $to): int
    {
        if ($from->uuid === $to->uuid || $from->programme_uuid !== $to->programme_uuid) {
            throw new \InvalidArgumentException('Choose another distribution in the same programme.');
        }
        $this->expectStatus($to, [DistributionEvent::PLANNED, DistributionEvent::OPEN], 'given farmers');

        $beneficiary = $this->beneficiary($from->programme, $beneficiaryId);

        return Entitlement::where('beneficiary_uuid', $beneficiary->uuid)
            ->where('distribution_event_uuid', $from->uuid)
            ->whereIn('status', [Entitlement::OPEN, Entitlement::PARTIALLY_COLLECTED])
            ->update(['distribution_event_uuid' => $to->uuid, 'delivery_model' => $to->kind === 'route' ? 'door_to_door' : 'distribution_point', 'attempts' => 0]);
    }

    /**
     * Stock found damaged, spoilt or missing during the day. It leaves the
     * event's stock and is counted in the reconciliation.
     */
    public function discrepancy(DistributionEvent $event, array $data, ?string $userUuid, ?string $clientId = null): Discrepancy
    {
        $this->expectStatus($event, [DistributionEvent::OPEN], 'given a discrepancy');
        if (!in_array($data['reason'] ?? null, Discrepancy::REASONS, true)) {
            throw new \InvalidArgumentException('The reason must be one of: ' . implode(', ', Discrepancy::REASONS) . '.');
        }
        if (!is_numeric($data['quantity'] ?? null) || $data['quantity'] <= 0) {
            throw new \InvalidArgumentException('The quantity must be more than 0.');
        }
        if (trim((string) ($data['notes'] ?? '')) === '') {
            throw new \InvalidArgumentException('Explain what happened.');
        }

        return DB::transaction(function () use ($event, $data, $userUuid, $clientId) {
            $discrepancy = Discrepancy::create([
                'client_id'       => $clientId,
                'company_uuid'    => $event->company_uuid,
                'programme_uuid'  => $event->programme_uuid,
                'subject_type'    => 'event',
                'subject_uuid'    => $event->uuid,
                'sku'             => $data['sku'],
                'lot'             => (string) ($data['lot'] ?? ''),
                'quantity'        => (float) $data['quantity'],
                'reason'          => $data['reason'],
                'liable_party'    => in_array($data['liable_party'] ?? null, Discrepancy::LIABLE, true) ? $data['liable_party'] : 'unknown',
                'notes'           => trim((string) $data['notes']),
                'evidence'        => $data['evidence'] ?? null,
                'status'          => 'open',
                'created_by_uuid' => $userUuid,
            ]);

            $this->stock->move([
                'company_uuid'   => $event->company_uuid,
                'programme_uuid' => $event->programme_uuid,
                'holder_type'    => 'event',
                'holder_uuid'    => $event->uuid,
                'sku'            => $data['sku'],
                'lot'            => (string) ($data['lot'] ?? ''),
                'quantity'       => -(float) $data['quantity'],
                'reason'         => 'discrepancy',
                'reference_type' => 'discrepancy',
                'reference_uuid' => $discrepancy->uuid,
                'user_uuid'      => $userUuid,
            ]);

            return $discrepancy;
        });
    }

    /**
     * @param list<array{sku: string, lot?: string, quantity: float}> $closing   counted at close
     * @param list<array{name: string, role: string}>                $witnesses
     */
    public function close(DistributionEvent $event, array $closing, array $witnesses, ?string $userUuid, ?string $clientId = null, ?\DateTimeInterface $at = null): DistributionEvent
    {
        $this->expectStatus($event, [DistributionEvent::OPEN], 'closed');
        $witnesses = $this->witnesses($witnesses);

        $closing = array_map(fn ($line) => ['sku' => $line['sku'], 'lot' => (string) ($line['lot'] ?? ''), 'quantity' => (float) ($line['quantity'] ?? 0)], $closing);
        $handed  = [];
        foreach (Handover::where('event_uuid', $event->uuid)->where('status', 'sealed')->whereIn('outcome', ['delivered', 'partial'])->get() as $handover) {
            foreach ($handover->lines ?? [] as $line) {
                $handed[] = ['sku' => $line['sku'], 'lot' => $line['lot'], 'quantity' => $line['quantity']];
            }
        }
        $discrepancies = $event->discrepancies()->get()->map(fn ($d) => ['sku' => $d->sku, 'lot' => $d->lot, 'quantity' => $d->quantity])->all();

        $result = (new StockReconciler((float) ($event->programme->rules['reconciliation_tolerance'] ?? 0)))
            ->reconcile($event->opening_stock ?? [], $handed, $closing, $discrepancies);
        if (!$result->balanced) {
            throw new HandoverRefused('unbalanced', 'The stock does not balance. Recount, or record what happened to the missing stock as a discrepancy.', ['lines' => $result->unbalanced()]);
        }

        DB::transaction(function () use ($event, $closing, $witnesses, $result, $userUuid, $clientId, $at) {
            // What's left goes back to the site. The ledger balance equals the counted
            // closing stock here, because the reconciliation balanced.
            foreach ($this->stock->onHand('event', $event->uuid) as $line) {
                if ($line['quantity'] <= 0) {
                    continue;
                }
                $this->stock->transfer(
                    ['holder_type' => 'event', 'holder_uuid' => $event->uuid, 'reason' => 'from_event'],
                    ['holder_type' => 'site', 'holder_uuid' => $event->site_uuid, 'reason' => 'from_event'],
                    $line,
                    ['company_uuid' => $event->company_uuid, 'programme_uuid' => $event->programme_uuid, 'reference_type' => 'event', 'reference_uuid' => $event->uuid, 'user_uuid' => $userUuid]
                );
            }

            $totals = $this->totals($event);
            $event->update([
                'status'         => DistributionEvent::CLOSED,
                'closed_at'      => $at && $at <= now() ? $at : now(),
                'closed_by_uuid' => $userUuid,
                'close_client_id' => $clientId,
                'closing_stock'  => $closing,
                'witnesses'      => array_merge($event->witnesses ?? [], ['close' => $witnesses]),
                'reconciliation' => $result->toArray(),
                'totals'         => $totals,
            ]);

            $event->update(['seal_hash' => Seal::hash([
                'event'          => $event->public_id,
                'site'           => $event->site->public_id,
                'opened'         => (string) $event->opened_at,
                'closed'         => (string) $event->closed_at,
                'opening'        => $event->opening_stock,
                'closing'        => $closing,
                'witnesses'      => $event->witnesses,
                'reconciliation' => $result->toArray(),
                'handovers'      => Handover::where('event_uuid', $event->uuid)->orderBy('id')->pluck('seal_hash')->all(),
            ])]);
        });

        return $event->refresh();
    }

    /**
     * People served and quantities, by sex and age band (REPORTING-AUDIT.md §3).
     */
    public function totals(DistributionEvent $event): array
    {
        $handovers = Handover::where('event_uuid', $event->uuid)->where('status', 'sealed')->with('beneficiary')->get();
        $served    = $handovers->whereIn('outcome', ['delivered', 'partial'])->unique('beneficiary_uuid');

        $quantities = [];
        foreach ($handovers->whereIn('outcome', ['delivered', 'partial']) as $handover) {
            foreach ($handover->lines ?? [] as $line) {
                $quantities[$line['sku']] = round(($quantities[$line['sku']] ?? 0) + $line['quantity'], 3);
            }
        }
        ksort($quantities);

        $year = (int) ($event->opened_at ?? now())->format('Y');

        return [
            'served'          => AgeBands::totals($served->map(fn ($h) => ['sex' => $h->beneficiary?->sex, 'birth_year' => $h->beneficiary?->birth_year])->values()->all(), $year),
            'handovers'       => $handovers->whereIn('outcome', ['delivered', 'partial'])->count(),
            'partial'         => $handovers->where('outcome', 'partial')->count(),
            'failed_attempts' => $handovers->where('outcome', 'failed')->count(),
            'proxy'           => $handovers->where('received_by', 'proxy')->count(),
            'identity_levels' => $handovers->whereIn('outcome', ['delivered', 'partial'])->countBy('identity_level')->sortKeys()->all(),
            'flagged'         => $handovers->filter(fn ($h) => !empty($h->flags))->count(),
            'quantities'      => $quantities,
            'assigned'        => Entitlement::where('distribution_event_uuid', $event->uuid)->distinct()->count('beneficiary_uuid'),
        ];
    }

    /**
     * The data for the distribution report (EventReportRenderer).
     */
    public function report(DistributionEvent $event): array
    {
        $event->loadMissing(['programme', 'site']);
        $areas = $this->areas->forCountry($event->site->country ?: $event->programme->country);
        $place = implode(', ', array_filter([
            $event->site->village,
            $event->site->admin_3 ? $areas->find($event->site->admin_3)?->name : null,
            $event->site->admin_2 ? $areas->find($event->site->admin_2)?->name : null,
        ]));

        $handovers = Handover::where('event_uuid', $event->uuid)->with('beneficiary')->orderBy('captured_at')->orderBy('id')->get();

        // Times in the programme country's local time (GMT in Sierra Leone, WAT in Nigeria).
        $tz    = $this->countries->has((string) $event->programme->country) ? $this->countries->get($event->programme->country)->timezone : 'UTC';
        $local = fn ($time, string $format) => $time ? $time->copy()->setTimezone($tz)->format($format) : null;

        return [
            'programme' => ['name' => $event->programme->name, 'season' => $event->programme->season],
            'event'     => [
                'public_id' => $event->public_id,
                'name'      => $event->name,
                'kind'      => $event->kind,
                'status'    => $event->status,
                'opened_at' => $local($event->opened_at, 'd/m/Y H:i'),
                'closed_at' => $local($event->closed_at, 'd/m/Y H:i'),
                'timezone'  => $tz,
                'seal_hash' => $event->seal_hash,
            ],
            'site'           => ['name' => $event->site->name, 'place' => $place],
            'totals'         => $event->totals ?? $this->totals($event),
            'reconciliation' => $event->reconciliation,
            'discrepancies'  => $event->discrepancies()->get()->map(fn ($d) => $d->only(['sku', 'lot', 'quantity', 'reason', 'liable_party', 'notes']))->all(),
            'witnesses'      => $event->witnesses ?? [],
            'handovers'      => $handovers->map(fn (Handover $h) => [
                'time'           => $local($h->captured_at, 'H:i'),
                'name'           => $h->beneficiary?->name,
                'beneficiary'    => $h->beneficiary?->public_id,
                'sex'            => $h->beneficiary?->sex === 'female' ? 'F' : ($h->beneficiary?->sex === 'male' ? 'M' : ''),
                'received_by'    => $h->received_by ?? '-',
                'recipient_name' => $h->recipient_name,
                'identity_level' => $h->identity_level,
                'lines'          => $h->lines ?? [],
                'outcome'        => $h->status === 'voided' ? 'voided' : $h->outcome,
                'failure_reason' => $h->failure_reason,
                'flags'          => $h->flags ?? [],
                'seal_hash'      => $h->seal_hash,
            ])->all(),
            'generated_at' => $local(now(), 'd/m/Y H:i'),
        ];
    }

    /**
     * Who the handover is for: by card code (preferred) or by beneficiary id.
     *
     * @return array{0: Beneficiary, 1: bool} [beneficiary, card checked and valid]
     */
    private function identify(Programme $programme, array $input): array
    {
        $code = trim((string) ($input['card_code'] ?? ''));
        if ($code !== '') {
            $check = $this->cards->check($programme, $code);
            if (!$check['valid']) {
                throw new HandoverRefused('card_' . $check['reason'], match ($check['reason']) {
                    'revoked'         => 'This card has been replaced. Ask for the farmer\'s new card.',
                    'bad_signature', 'malformed' => 'This card code is not genuine or was mistyped.',
                    'wrong_programme' => 'This card is for another programme.',
                    default           => 'This card is not valid (' . $check['reason'] . ').',
                });
            }

            return [Beneficiary::where('programme_uuid', $programme->uuid)->where('public_id', $check['beneficiary']['public_id'])->firstOrFail(), true];
        }

        return [$this->beneficiary($programme, (string) ($input['beneficiary'] ?? '')), false];
    }

    private function beneficiary(Programme $programme, string $id): Beneficiary
    {
        $beneficiary = $id === '' ? null : Beneficiary::where('programme_uuid', $programme->uuid)->where(fn ($q) => $q->where('public_id', $id)->orWhere('uuid', $id))->first();
        if (!$beneficiary) {
            throw new HandoverRefused('not_found', 'Scan the farmer\'s card or give their beneficiary id.');
        }

        return $beneficiary;
    }

    private function lines(array $input, $here, $items, Beneficiary $beneficiary, DistributionEvent $event, Programme $programme): array
    {
        if (!$input) {
            throw new \InvalidArgumentException('Give at least one line handed over.');
        }

        $recalled = collect($programme->rules['recalled_lots'] ?? [])->map(fn ($r) => ($r['sku'] ?? '') . '|' . ($r['lot'] ?? ''))->all();
        $today    = now()->format('Y-m-d');
        $bySku    = [];
        $lines    = [];

        foreach ($input as $line) {
            $sku      = (string) ($line['sku'] ?? '');
            $lot      = (string) ($line['lot'] ?? '');
            $quantity = (float) ($line['quantity'] ?? 0);

            if (!isset($here[$sku])) {
                throw new HandoverRefused('not_entitled', "This farmer has no entitlement to {$sku} at this distribution.");
            }
            if ($quantity <= 0) {
                throw new \InvalidArgumentException("The quantity of {$sku} must be more than 0.");
            }
            if (in_array($sku . '|' . $lot, $recalled, true)) {
                throw new HandoverRefused('lot_recalled', "Lot {$lot} of {$sku} has been recalled. Do not hand it over.");
            }
            $expiry = $this->lotExpiry('event', $event->uuid, $sku, $lot);
            if ($expiry !== null && $expiry < $today) {
                throw new HandoverRefused('lot_expired', "Lot {$lot} of {$sku} expired on {$expiry}. Do not hand it over.");
            }
            if ($items[$sku]->restricted && $beneficiary->trained_restricted_products === null) {
                throw new HandoverRefused('not_trained', "{$items[$sku]->name} is a restricted product and this farmer has no recorded safety training.");
            }

            $entitlement = $here[$sku];
            $remaining   = round($entitlement->quantity - $entitlement->collected_quantity, 3);
            $bySku[$sku] = ($bySku[$sku] ?? 0) + $quantity;
            if ($bySku[$sku] > $remaining + 0.0005) {
                throw new HandoverRefused('over_entitlement', sprintf('%s: %s %s left to collect, %s asked for.', $sku, $this->number($remaining), $entitlement->unit, $this->number($bySku[$sku])));
            }

            $lines[] = ['sku' => $sku, 'lot' => $lot, 'quantity' => $quantity, 'unit' => $entitlement->unit, 'remaining_before' => $remaining];
        }

        return $lines;
    }

    /**
     * @return array{0: ?int, 1: list<string>} [distance in metres, flags]
     */
    private function locationCheck(DistributionEvent $event, Beneficiary $beneficiary, array $input): array
    {
        $lat = $input['latitude'] ?? null;
        $lon = $input['longitude'] ?? null;
        if (!is_numeric($lat) || !is_numeric($lon)) {
            return [null, ['no_location']];
        }

        // Door-to-door: the household (200 m). Distribution point: the site (500 m unless set).
        if ($event->kind === 'route') {
            [$refLat, $refLon, $radius] = [$beneficiary->latitude, $beneficiary->longitude, 200];
        } else {
            $site                       = $event->site;
            [$refLat, $refLon, $radius] = [$site->latitude, $site->longitude, $site->geofence_radius_m ?: 500];
        }
        if ($refLat === null || $refLon === null) {
            return [null, ['no_reference_location']];
        }

        $distance = (int) round(Distance::metres((float) $lat, (float) $lon, (float) $refLat, (float) $refLon));

        return [$distance, $distance > $radius ? ['outside_geofence'] : []];
    }

    private function lotExpiry(string $holderType, string $holderUuid, string $sku, string $lot): ?string
    {
        $expiry = \Fleetbase\Pod\Models\StockMovement::where('holder_type', $holderType)->where('holder_uuid', $holderUuid)->where('sku', $sku)->where('lot', $lot)->whereNotNull('expiry')->max('expiry');

        return $expiry ? substr((string) $expiry, 0, 10) : null;
    }

    private function remaining(string $beneficiaryUuid, DistributionEvent $event): array
    {
        return Entitlement::where('beneficiary_uuid', $beneficiaryUuid)->where('distribution_event_uuid', $event->uuid)->get()
            ->map(fn ($e) => ['sku' => $e->sku, 'entitled' => $e->quantity, 'collected' => $e->collected_quantity, 'remaining' => round($e->quantity - $e->collected_quantity, 3), 'unit' => $e->unit, 'status' => $e->status])
            ->values()->all();
    }

    private function sealable(Handover $handover, DistributionEvent $event, Beneficiary $beneficiary): array
    {
        return [
            'handover'    => $handover->public_id,
            'event'       => $event->public_id,
            'beneficiary' => $beneficiary->public_id,
            'outcome'     => $handover->outcome,
            'reason'      => $handover->failure_reason,
            'received_by' => [$handover->received_by, $handover->recipient_name],
            'identity'    => [$handover->identity_level, $handover->identity_checks],
            'lines'       => $handover->lines,
            'evidence'    => $handover->evidence,
            'location'    => [$handover->latitude, $handover->longitude, $handover->distance_m],
            'captured_at' => (string) $handover->captured_at,
            'officer'     => $handover->officer_uuid,
        ];
    }

    private function witnesses(array $witnesses): array
    {
        $clean = [];
        foreach ($witnesses as $witness) {
            $name = trim((string) ($witness['name'] ?? ''));
            $role = $witness['role'] ?? '';
            if ($name === '') {
                continue;
            }
            if (!in_array($role, self::WITNESS_ROLES, true)) {
                throw new \InvalidArgumentException('A witness role must be one of: ' . implode(', ', self::WITNESS_ROLES) . '.');
            }
            $clean[] = ['name' => $name, 'role' => $role, 'signature' => $witness['signature'] ?? null];
        }
        if (!$clean) {
            throw new \InvalidArgumentException('At least one witness (a chief, group leader, extension officer...) must sign.');
        }

        return $clean;
    }

    private function maxAttempts(Programme $programme): int
    {
        return max(1, (int) ($programme->rules['max_attempts'] ?? 2));
    }

    private function expectStatus(DistributionEvent $event, array $allowed, string $action): void
    {
        if (!in_array($event->status, $allowed, true)) {
            throw new \LogicException("A distribution that is {$event->status} can't be {$action}.");
        }
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}
