<?php

namespace Fleetbase\Pod\Services;

use Fleetbase\Pod\Http\Requests\FieldRules;
use Fleetbase\Pod\Models\Beneficiary;
use Fleetbase\Pod\Models\DistributionEvent;
use Fleetbase\Pod\Models\Entitlement;
use Fleetbase\Pod\Models\Handover;
use Fleetbase\Pod\Models\Media;
use Fleetbase\Pod\Models\SyncConflict;
use Fleetbase\Pod\Support\Distribution\HandoverPolicy;
use Fleetbase\Pod\Support\Seal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The server side of the offline field app (DESIGN.md §6.6, AGRI-INPUTS.md §13):
 *
 *   bundle()  everything a phone needs to run a distribution day or route
 *             with no network, downloaded on depot Wi-Fi
 *   sync()    handovers and failed attempts recorded offline, sent in a batch;
 *             each is processed once (client ids), in capture order
 *   media()   photos and signatures uploaded after the record, checked against
 *             the SHA-256 the record committed to at capture
 */
class FieldSyncService
{
    public const MAX_BATCH = 100;

    public const MAX_MEDIA_BYTES = 5 * 1024 * 1024;

    public const MEDIA_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /** Records synced later than this are flagged (REPORTING-AUDIT.md AR-11). */
    public const LATE_SYNC_HOURS = 12;

    public function __construct(
        private DistributionService $distribution,
        private CardService $cards,
        private StockLedger $stock,
    ) {
    }

    /**
     * The offline bundle for one distribution day or route.
     */
    public function bundle(DistributionEvent $event): array
    {
        if (!in_array($event->status, [DistributionEvent::PLANNED, DistributionEvent::OPEN], true)) {
            throw new \LogicException("This distribution is {$event->status}; there is nothing to take to the field.");
        }

        $event->loadMissing(['programme', 'site']);
        $programme = $event->programme;
        $site      = $event->site;

        $entitlements = Entitlement::where('distribution_event_uuid', $event->uuid)->get()->groupBy('beneficiary_uuid');
        $people       = Beneficiary::whereIn('uuid', $entitlements->keys())->orderBy('name')->get();
        $served       = Handover::where('event_uuid', $event->uuid)->where('status', 'sealed')->get()->groupBy('beneficiary_uuid');

        $beneficiaries = $people->map(fn (Beneficiary $b) => [
            'id'         => $b->public_id,
            'name'       => $b->name,
            'sex'        => $b->sex,
            'birth_year' => $b->birth_year,
            'village'    => $b->village,
            'farmer_ref' => $b->farmer_ref,
            'verified'   => $b->isVerified(),
            'active'     => $b->status === 'active',
            'card_issue' => $b->card_issue,
            'trained'    => $b->trained_restricted_products !== null,
            // The last 4 of an ID are checked offline without sending them to the phone.
            'id_last4_hash' => $b->id_last4 ? hash('sha256', $b->public_id . '|' . strtoupper($b->id_last4)) : null,
            'latitude'      => $event->kind === 'route' ? $b->latitude : null,
            'longitude'     => $event->kind === 'route' ? $b->longitude : null,
            'entitlements'  => $entitlements[$b->uuid]->map(fn (Entitlement $e) => [
                'sku' => $e->sku, 'quantity' => $e->quantity, 'collected' => $e->collected_quantity, 'unit' => $e->unit, 'status' => $e->status, 'attempts' => $e->attempts,
            ])->values()->all(),
            'handovers' => ($served[$b->uuid] ?? collect())->map(fn (Handover $h) => ['id' => $h->public_id, 'outcome' => $h->outcome, 'at' => $h->captured_at?->toIso8601String()])->values()->all(),
        ])->values()->all();

        $stock = $event->status === DistributionEvent::OPEN ? $this->stock->onHand('event', $event->uuid) : $this->stock->onHand('site', $site->uuid);

        $bundle = [
            'format'    => 1,
            'event'     => [
                'id' => $event->public_id, 'name' => $event->name, 'kind' => $event->kind, 'status' => $event->status,
                'scheduled_date' => $event->scheduled_date?->format('Y-m-d'), 'opened_at' => $event->opened_at?->toIso8601String(),
            ],
            'site' => [
                'id' => $site->public_id, 'name' => $site->name, 'type' => $site->type, 'village' => $site->village,
                'latitude' => $site->latitude, 'longitude' => $site->longitude, 'geofence_radius_m' => $site->geofence_radius_m ?: ($event->kind === 'route' ? 200 : 500),
            ],
            'programme' => [
                'id' => $programme->public_id, 'name' => $programme->name, 'season' => $programme->season, 'country' => $programme->country,
                'handover_policy'          => $programme->rules['handover_policy'] ?? HandoverPolicy::DEFAULT,
                'recalled_lots'            => $programme->rules['recalled_lots'] ?? [],
                'max_attempts'             => max(1, (int) ($programme->rules['max_attempts'] ?? 2)),
                'verify_on_first_handover' => $programme->allowsUnverifiedEntitlements(),
            ],
            // The programme's card key, so cards are checked with no network. It can't
            // make cards for other programmes, and the installation secret never leaves the server.
            'card_key'        => base64_encode($this->cards->programmeKey($programme)),
            'items'           => $programme->items()->get()->map(fn ($i) => $i->only(['sku', 'name', 'category', 'unit', 'restricted', 'cold_chain']))->all(),
            'stock'           => $stock,
            'beneficiaries'   => $beneficiaries,
            'failure_reasons' => DistributionService::FAILURE_REASONS,
            'witness_roles'   => DistributionService::WITNESS_ROLES,
            'server_time'     => now()->toIso8601String(),
        ];
        $bundle['version'] = substr(Seal::hash(array_diff_key($bundle, ['server_time' => 1])), 0, 16);

        return $bundle;
    }

    /**
     * @param list<array> $items each: {kind: handover|attempt, event, client_handover_id, ...the online fields}
     *
     * @return array{results: list<array>, server_time: string}
     */
    public function sync(string $companyUuid, array $items, ?string $userUuid): array
    {
        if (count($items) > self::MAX_BATCH) {
            throw new \InvalidArgumentException('Send at most ' . self::MAX_BATCH . ' records at a time.');
        }

        // Process in the order things happened in the field.
        usort($items, fn ($a, $b) => strcmp((string) ($a['captured_at'] ?? ''), (string) ($b['captured_at'] ?? '')));

        $results = [];
        foreach ($items as $item) {
            $results[] = $this->syncOne($companyUuid, is_array($item) ? $item : [], $userUuid);
        }

        return ['results' => $results, 'server_time' => now()->toIso8601String()];
    }

    private function syncOne(string $companyUuid, array $item, ?string $userUuid): array
    {
        $clientId = (string) ($item['client_handover_id'] ?? '');
        $kind     = $item['kind'] ?? null;
        if ($clientId === '' || !in_array($kind, ['handover', 'attempt'], true)) {
            return ['client_handover_id' => $clientId, 'status' => 'invalid', 'error' => 'invalid', 'message' => 'Each record needs a kind (handover or attempt) and a client_handover_id.'];
        }

        // Already recorded or already in conflict: answer the same way again.
        if ($handover = Handover::where('company_uuid', $companyUuid)->where('client_handover_id', $clientId)->first()) {
            return ['client_handover_id' => $clientId, 'status' => 'replayed', 'handover' => $handover->public_id, 'outcome' => $handover->outcome];
        }
        if ($conflict = SyncConflict::where('company_uuid', $companyUuid)->where('client_handover_id', $clientId)->first()) {
            return ['client_handover_id' => $clientId, 'status' => 'conflict', 'error' => $conflict->error, 'message' => $conflict->message];
        }

        $event = DistributionEvent::where('company_uuid', $companyUuid)
            ->where(fn ($q) => $q->where('public_id', $item['event'] ?? '')->orWhere('uuid', $item['event'] ?? ''))
            ->first();

        try {
            if (!$event) {
                throw new HandoverRefused('event_not_found', 'The distribution in this record was not found.');
            }
            $data  = Validator::make($item, $kind === 'handover' ? FieldRules::handover() : FieldRules::attempt())->validate();
            $flags = $this->syncFlags($data['captured_at'] ?? null);

            if ($kind === 'handover') {
                $result = $this->distribution->handover($event, $data, $userUuid, $flags);

                return ['client_handover_id' => $clientId, 'status' => $result['replayed'] ? 'replayed' : 'accepted', 'handover' => $result['handover']->public_id, 'outcome' => $result['handover']->outcome, 'flags' => $result['handover']->flags, 'remaining' => $result['remaining']];
            }

            $result = $this->distribution->fail($event, $data, $userUuid, $flags);

            return ['client_handover_id' => $clientId, 'status' => 'accepted', 'handover' => $result['handover']->public_id, 'outcome' => 'failed', 'attempts' => $result['attempts'], 'needs_reassignment' => $result['needs_reassignment'], 'registry_review' => $result['registry_review']];
        } catch (HandoverRefused $e) {
            return $this->conflict($companyUuid, $event, $clientId, $kind, $item, $e->reason, $e->getMessage(), $e->details, $userUuid);
        } catch (ValidationException $e) {
            return $this->conflict($companyUuid, $event, $clientId, $kind, $item, 'invalid', implode(' ', $e->validator->errors()->all()), [], $userUuid);
        } catch (\InvalidArgumentException|\LogicException $e) {
            return $this->conflict($companyUuid, $event, $clientId, $kind, $item, 'refused', $e->getMessage(), [], $userUuid);
        }
    }

    /**
     * A record made offline that the server can't accept. For a handover the goods
     * have already been given, so it is kept for review, never dropped.
     */
    private function conflict(string $companyUuid, ?DistributionEvent $event, string $clientId, string $kind, array $item, string $error, string $message, array $details, ?string $userUuid): array
    {
        // Never store a full ID number a phone might have sent by mistake.
        unset($item['identity']['id_number']);

        SyncConflict::create([
            'company_uuid'       => $companyUuid,
            'event_uuid'         => $event?->uuid,
            'client_handover_id' => $clientId,
            'kind'               => $kind,
            'payload'            => $item,
            'error'              => $error,
            'message'            => $message,
            'details'            => $details ?: null,
            'status'             => 'open',
            'user_uuid'          => $userUuid,
        ]);

        return ['client_handover_id' => $clientId, 'status' => 'conflict', 'error' => $error, 'message' => $message] + ($details ? ['details' => $details] : []);
    }

    /**
     * Flags from the device's capture time: the clock is ahead of the server, or
     * the record reached the server more than LATE_SYNC_HOURS after capture.
     */
    private function syncFlags(?string $capturedAt): array
    {
        if (!$capturedAt) {
            return [];
        }
        $captured = Carbon::parse($capturedAt);
        $flags    = [];
        if ($captured->greaterThan(now()->addMinutes(10))) {
            $flags[] = 'clock_ahead';
        }
        if ($captured->lessThan(now()->subHours(self::LATE_SYNC_HOURS))) {
            $flags[] = 'late_sync';
        }

        return $flags;
    }

    /**
     * Store a photo or signature captured offline.
     *
     * @return array{media: Media, replayed: bool}
     */
    public function media(string $companyUuid, UploadedFile $file, string $clientMediaId, string $sha256, ?string $kind, ?string $userUuid): array
    {
        $sha256 = strtolower($sha256);
        if (!preg_match('/^[a-f0-9]{64}$/', $sha256)) {
            throw new \InvalidArgumentException('sha256 must be 64 hexadecimal characters.');
        }
        if (!preg_match('/^[A-Za-z0-9._\-]{1,64}$/', $clientMediaId)) {
            throw new \InvalidArgumentException('client_media_id may use letters, digits, dots, dashes and underscores (at most 64).');
        }

        $existing = Media::where('company_uuid', $companyUuid)->where('client_media_id', $clientMediaId)->first();
        if ($existing) {
            if ($existing->sha256 !== $sha256) {
                throw new MediaConflict('A different file was already uploaded under this id.');
            }

            return ['media' => $existing, 'replayed' => true];
        }

        if ($file->getSize() > self::MAX_MEDIA_BYTES) {
            throw new \InvalidArgumentException('The file is larger than 5 MB. Photos should be compressed on the phone first.');
        }
        // The type from the file's content, not its name or what the client claims.
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()) ?: '';
        if (!isset(self::MEDIA_TYPES[$mime])) {
            throw new \InvalidArgumentException('Only JPEG, PNG and WebP images can be uploaded.');
        }
        $actual = hash_file('sha256', $file->getRealPath());
        if (!hash_equals($sha256, $actual)) {
            // Damaged in transit, or not the file the record refers to. The phone keeps it and retries.
            throw new \InvalidArgumentException('The file does not match its SHA-256. It may have been damaged in transit: try again.');
        }

        $disk = config('pod.media.disk', config('pod.import.disk', config('filesystems.default')));
        $path = $file->storeAs('pod/media/' . $companyUuid . '/' . now()->format('Y/m'), $sha256 . '.' . self::MEDIA_TYPES[$mime], ['disk' => $disk]);

        return ['media' => Media::create([
            'company_uuid'    => $companyUuid,
            'client_media_id' => $clientMediaId,
            'sha256'          => $sha256,
            'kind'            => $kind,
            'mime'            => $mime,
            'size'            => $file->getSize(),
            'disk'            => $disk,
            'path'            => $path,
            'user_uuid'       => $userUuid,
        ]), 'replayed' => false];
    }

    /**
     * Photos and signatures referred to by an event's handovers, and which have arrived.
     *
     * @return array{expected: int, uploaded: int, missing: list<string>}
     */
    public function mediaStatus(DistributionEvent $event): array
    {
        $expected = [];
        foreach (Handover::where('event_uuid', $event->uuid)->get() as $handover) {
            foreach ($handover->evidence ?? [] as $item) {
                if (!empty($item['media'])) {
                    $expected[$item['media']] = $item['sha256'] ?? null;
                }
            }
        }

        $uploaded = Media::where('company_uuid', $event->company_uuid)->whereIn('client_media_id', array_keys($expected))->pluck('sha256', 'client_media_id')->all();
        $missing  = array_values(array_filter(array_keys($expected), fn ($id) => !isset($uploaded[$id]) || ($expected[$id] && $uploaded[$id] !== $expected[$id])));

        return ['expected' => count($expected), 'uploaded' => count($expected) - count($missing), 'missing' => $missing];
    }

    /**
     * Stream a stored media file (for reviewers).
     */
    public function read(Media $media): string
    {
        return Storage::disk($media->disk)->get($media->path);
    }
}
