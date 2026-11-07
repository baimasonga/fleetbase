<?php

namespace Fleetbase\Pod\Http\Controllers\Internal\v1;

use Fleetbase\Pod\Models\DistributionEvent;
use Fleetbase\Pod\Models\SyncConflict;
use Fleetbase\Pod\Services\FieldSyncService;
use Fleetbase\Pod\Services\MediaConflict;
use Illuminate\Http\Request;

/**
 * For the offline field app:
 *
 *   GET  field/events               open and planned distributions the phone can download
 *   GET  events/{id}/bundle         the offline bundle
 *   POST field/sync                 {items: [...]} records made offline
 *   POST field/media                multipart: file, client_media_id, sha256, kind
 *   GET  events/{id}/media-status   which photos and signatures have arrived
 *   GET  events/{id}/conflicts      records the server could not accept
 */
class FieldController extends PodController
{
    public function __construct(private FieldSyncService $field)
    {
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

        return $this->guarded(fn () => response()->json($this->field->sync($this->companyUuid(), $items, $this->userUuid())));
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
