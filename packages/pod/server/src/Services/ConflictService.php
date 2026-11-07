<?php

namespace Fleetbase\Pod\Services;

use Fleetbase\Pod\Models\Beneficiary;
use Fleetbase\Pod\Models\DistributionEvent;
use Fleetbase\Pod\Models\Handover;
use Fleetbase\Pod\Models\Media;
use Fleetbase\Pod\Models\ProgrammeItem;
use Fleetbase\Pod\Models\SyncConflict;
use Illuminate\Support\Facades\DB;

/**
 * Supervisor review of records made offline that the server couldn't accept
 * (DESIGN.md §6.6.7, REPORTING-AUDIT.md §7).
 *
 * For a handover the goods have already left, so the supervisor decides:
 *
 *   written_off  the goods are lost to the programme: recorded as missing
 *                stock on the day (only while the day is open; once it is
 *                closed the loss is already in the closing reconciliation)
 *   recovered    the goods were taken back
 *   reviewed     looked at and explained; nothing to record (e.g. a second
 *                opening count, a closing sent after the day was closed)
 *
 * Whoever made the record can't resolve it.
 */
class ConflictService
{
    public const RESOLUTIONS = ['written_off', 'recovered', 'reviewed'];

    public function __construct(private DistributionService $distribution)
    {
    }

    /**
     * @return list<array>
     */
    public function list(string $companyUuid, string $status = 'open'): array
    {
        return SyncConflict::where('company_uuid', $companyUuid)
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->with('event:uuid,public_id,name,status')
            ->orderByDesc('created_at')
            ->limit(500)
            ->get()
            ->map(fn (SyncConflict $c) => $this->summary($c))
            ->all();
    }

    public function summary(SyncConflict $conflict): array
    {
        $beneficiary = $this->beneficiaryOf($conflict);

        return [
            'id'          => $conflict->uuid,
            'kind'        => $conflict->kind,
            'error'       => $conflict->error,
            'message'     => $conflict->message,
            'status'      => $conflict->status,
            'resolution'  => $conflict->resolution,
            'record'      => $conflict->client_handover_id,
            'captured_at' => $conflict->payload['captured_at'] ?? null,
            'synced_at'   => $conflict->created_at?->toIso8601String(),
            'event'       => $conflict->event ? ['id' => $conflict->event->public_id, 'name' => $conflict->event->name, 'status' => $conflict->event->status] : null,
            'farmer'      => $beneficiary ? ['id' => $beneficiary->public_id, 'name' => $beneficiary->name, 'village' => $beneficiary->village] : null,
        ];
    }

    /**
     * Everything a supervisor needs to decide: the record as made on the phone,
     * and what the server already holds that it clashed with.
     */
    public function detail(SyncConflict $conflict): array
    {
        $payload = $conflict->payload ?? [];
        $event   = $conflict->event;
        $items   = $event ? ProgrammeItem::where('programme_uuid', $event->programme_uuid)->pluck('name', 'sku')->all() : [];
        $name    = fn ($sku) => $items[$sku] ?? $sku;

        $lines = array_map(fn ($l) => ['sku' => $l['sku'] ?? '', 'name' => $name($l['sku'] ?? ''), 'lot' => $l['lot'] ?? '', 'quantity' => (float) ($l['quantity'] ?? 0)], $payload['lines'] ?? $payload['stock'] ?? []);

        $evidence = [];
        foreach ($payload['evidence'] ?? [] as $item) {
            $evidence[] = ['type' => $item['type'] ?? '', 'media' => $item['media'] ?? null, 'name' => $item['name'] ?? null];
        }
        foreach ($payload['witnesses'] ?? [] as $w) {
            $evidence[] = ['type' => 'witness_signature', 'media' => $w['signature'] ?? null, 'name' => trim(($w['name'] ?? '') . ' (' . str_replace('_', ' ', $w['role'] ?? '') . ')')];
        }
        $uploaded = Media::where('company_uuid', $conflict->company_uuid)->whereIn('client_media_id', array_filter(array_column($evidence, 'media')))->pluck('client_media_id')->all();
        foreach ($evidence as &$item) {
            $item['uploaded'] = $item['media'] && in_array($item['media'], $uploaded, true);
        }
        unset($item);

        // What the server already holds.
        $beneficiary = $this->beneficiaryOf($conflict);
        $existing    = [];
        if ($event && $beneficiary) {
            $existing['handovers'] = Handover::where('event_uuid', $event->uuid)->where('beneficiary_uuid', $beneficiary->uuid)->orderBy('captured_at')->get()
                ->map(fn (Handover $h) => [
                    'id'          => $h->public_id,
                    'record'      => $h->client_handover_id,
                    'outcome'     => $h->outcome,
                    'captured_at' => $h->captured_at?->toIso8601String(),
                    'received_by' => $h->received_by,
                    'recipient'   => $h->recipient_name,
                    'lines'       => array_map(fn ($l) => $l + ['name' => $name($l['sku'])], $h->lines ?? []),
                ])->all();
        }
        if ($event && in_array($conflict->kind, ['open', 'close'], true)) {
            $existing['opening_stock'] = $event->opening_stock;
            $existing['closing_stock'] = $event->closing_stock;
        }

        return $this->summary($conflict) + [
            'details'            => $conflict->details,
            'lines'              => $lines,
            'received_by'        => $payload['received_by'] ?? null,
            'recipient'          => $payload['recipient_name'] ?? null,
            'identity'           => $payload['identity'] ?? null,
            'evidence'           => $evidence,
            'location'           => isset($payload['latitude'], $payload['longitude']) ? ['latitude' => $payload['latitude'], 'longitude' => $payload['longitude']] : null,
            'existing'           => $existing,
            'resolution_notes'   => $conflict->resolution_notes,
            'resolved_at'        => $conflict->resolved_at?->toIso8601String(),
            'options'            => $this->options($conflict),
        ];
    }

    /**
     * The resolutions that make sense for this conflict now.
     */
    public function options(SyncConflict $conflict): array
    {
        if ($conflict->status !== 'open') {
            return [];
        }
        if ($conflict->kind !== 'handover' || empty($conflict->payload['lines'])) {
            return ['reviewed'];
        }
        $options = [];
        if ($conflict->event?->status === DistributionEvent::OPEN) {
            $options[] = 'written_off';
        }

        return array_merge($options, ['recovered', 'reviewed']);
    }

    public function resolve(SyncConflict $conflict, string $resolution, string $notes, ?string $userUuid): SyncConflict
    {
        if ($conflict->status !== 'open') {
            throw new \LogicException('This conflict is already resolved.');
        }
        if (!in_array($resolution, self::RESOLUTIONS, true)) {
            throw new \InvalidArgumentException('The resolution must be one of: ' . implode(', ', self::RESOLUTIONS) . '.');
        }
        if (!in_array($resolution, $this->options($conflict), true)) {
            throw new \LogicException($resolution === 'written_off'
                ? 'The day is no longer open, so the loss can\'t be recorded here: it belongs in the closing reconciliation. Choose "recovered" or "reviewed".'
                : 'That resolution does not apply to this conflict.');
        }
        if (mb_strlen(trim($notes)) < 10) {
            throw new \InvalidArgumentException('Explain what happened and what was done (at least a sentence).');
        }
        if ($userUuid !== null && $conflict->user_uuid !== null && $userUuid === $conflict->user_uuid) {
            throw new \LogicException('The person whose record this is can\'t resolve it. Ask another supervisor.');
        }

        return DB::transaction(function () use ($conflict, $resolution, $notes, $userUuid) {
            $created = [];
            if ($resolution === 'written_off') {
                foreach ($conflict->payload['lines'] as $line) {
                    if ((float) ($line['quantity'] ?? 0) <= 0) {
                        continue;
                    }
                    $created[] = $this->distribution->discrepancy($conflict->event, [
                        'sku'          => $line['sku'],
                        'lot'          => $line['lot'] ?? '',
                        'quantity'     => (float) $line['quantity'],
                        'reason'       => 'other',
                        'liable_party' => 'officer',
                        'notes'        => 'Sync conflict ' . $conflict->client_handover_id . ' (' . $conflict->error . '): ' . trim($notes),
                    ], $userUuid)->public_id;
                }
            }

            $conflict->update([
                'status'           => 'resolved',
                'resolution'       => $resolution,
                'resolution_notes' => trim($notes),
                'discrepancies'    => $created ?: null,
                'resolved_by_uuid' => $userUuid,
                'resolved_at'      => now(),
            ]);

            return $conflict;
        });
    }

    private function beneficiaryOf(SyncConflict $conflict): ?Beneficiary
    {
        $payload = $conflict->payload ?? [];
        $id      = $payload['beneficiary'] ?? null;
        if (!$id && !empty($payload['card_code'])) {
            $parts = explode('.', (string) $payload['card_code']);
            $id    = $parts[2] ?? null;
        }
        if (!$id) {
            return null;
        }

        return Beneficiary::where('company_uuid', $conflict->company_uuid)->where(fn ($q) => $q->where('public_id', $id)->orWhere('uuid', $id))->first();
    }
}
