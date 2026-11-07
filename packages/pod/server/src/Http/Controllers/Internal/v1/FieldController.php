<?php

namespace Fleetbase\Pod\Http\Controllers\Internal\v1;

use Fleetbase\Pod\Auth\Gate;
use Fleetbase\Pod\Models\DistributionEvent;
use Fleetbase\Pod\Models\SyncConflict;
use Fleetbase\Pod\Services\FieldSyncService;
use Fleetbase\Pod\Services\MediaConflict;
use Illuminate\Http\Request;

/**
 * For the offline field app:
 *
 *   GET  me                         what the signed-in person may do in the app
 *   GET  field/events               open and planned distributions the phone can download
 *   GET  events/{id}/bundle         the offline bundle
 *   POST field/sync                 {items: [...]} records made offline; each kind needs its own
 *                                   permission too (opening, closing, handover, discrepancy), and a
 *                                   record the person may not send answers `forbidden` and is not
 *                                   stored: the phone keeps it until an administrator gives the role
 *   POST field/media                multipart: file, client_media_id, sha256, kind
 *   GET  events/{id}/media-status   which photos and signatures have arrived
 *   GET  events/{id}/conflicts      records the server could not accept
 */
class FieldController extends PodController
{
    /** The permission each kind of field record needs, besides `pod sync field`. */
    public const KIND_PERMISSIONS = [
        'open'        => 'pod open distribution',
        'close'       => 'pod close distribution',
        'handover'    => 'pod create handover',
        'attempt'     => 'pod create handover',
        'discrepancy' => 'pod create discrepancy',
    ];

    /** What the field app asks about, to show or hide its screens. */
    public const CAPABILITIES = [
        'field'            => 'pod sync field',
        'open_day'         => 'pod open distribution',
        'close_day'        => 'pod close distribution',
        'hand_over'        => 'pod create handover',
        'record_stock'     => 'pod create discrepancy',
        'review_conflicts' => 'pod list conflict',
        'resolve_conflicts' => 'pod resolve conflict',
    ];

    public function __construct(private FieldSyncService $field, private Gate $gate)
    {
    }

    public function me()
    {
        return response()->json([
            'user' => $this->userUuid(),
            'can'  => array_map(fn ($permission) => $this->gate->allows($permission), self::CAPABILITIES),
        ]);
    }

    public function events()
    {
        $events = DistributionEvent::where('company_uuid', $this->companyUuid())
            ->whereIn('status', [DistributionEvent::PLANNED, DistributionEvent::OPEN])
            ->with(['site:uuid,public_id,name,type,village', 'programme:uuid,public_id,name,season'])
            ->orderBy('scheduled_date')->orderBy('name')
            ->get()
            ->map(fn ($e) => [
                'id'             => $e->public_id,
                'name'           => $e->name,
                'kind'           => $e->kind,
                'status'         => $e->status,
                'scheduled_date' => $e->scheduled_date?->format('Y-m-d'),
                'site'           => $e->site?->only(['public_id', 'name', 'type', 'village']),
                'programme'      => $e->programme?->only(['public_id', 'name', 'season']),
            ]);

        return response()->json(['events' => $events]);
    }

    public function bundle(string $id)
    {
        $event = $this->event($id);

        return $this->guarded(fn () => response()->json($this->field->bundle($event)));
    }

    public function sync(Request $request)
    {
        $items = $request->validate(['items' => ['required', 'array', 'min:1', 'max:' . FieldSyncService::MAX_BATCH], 'items.*' => ['array']])['items'];

        $allowed   = [];
        $forbidden = [];
        foreach ($items as $item) {
            $permission = self::KIND_PERMISSIONS[$item['kind'] ?? ''] ?? null;
            if ($permission && !$this->gate->allows($permission)) {
                $forbidden[] = [
                    'client_handover_id' => (string) ($item['client_handover_id'] ?? ''),
                    'status'             => 'forbidden',
                    'error'              => 'forbidden',
                    'message'            => 'Your account is not allowed to send this record (' . $permission . '). It stays on the phone: ask an administrator to give you the Invendis Field Officer role.',
                ];
                continue;
            }
            $allowed[] = $item;
        }

        return $this->guarded(function () use ($allowed, $forbidden) {
            $out = $allowed ? $this->field->sync($this->companyUuid(), $allowed, $this->userUuid()) : ['results' => [], 'server_time' => now()->toIso8601String()];
            $out['results'] = array_merge($out['results'], $forbidden);

            return response()->json($out);
        });
    }

    public function media(Request $request)
    {
        $data = $request->validate([
            'file'            => ['required', 'file'],
            'client_media_id' => ['required', 'string', 'max:64'],
            'sha256'          => ['required', 'string', 'size:64'],
            'kind'            => ['nullable', 'string', 'max:24'],
        ]);

        try {
            $result = $this->field->media($this->companyUuid(), $request->file('file'), $data['client_media_id'], $data['sha256'], $data['kind'] ?? null, $this->userUuid());
        } catch (MediaConflict $e) {
            return $this->error($e->getMessage(), 409);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }

        return response()->json(['media' => $result['media'], 'replayed' => $result['replayed']], $result['replayed'] ? 200 : 201);
    }

    public function mediaStatus(string $id)
    {
        return response()->json($this->field->mediaStatus($this->event($id)));
    }

    public function conflicts(string $id)
    {
        $event = $this->event($id);

        return response()->json(['conflicts' => SyncConflict::where('event_uuid', $event->uuid)->orderBy('id')->get()]);
    }
}
