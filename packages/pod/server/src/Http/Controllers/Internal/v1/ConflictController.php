<?php

namespace Fleetbase\Pod\Http\Controllers\Internal\v1;

use Fleetbase\Pod\Models\Media;
use Fleetbase\Pod\Models\SyncConflict;
use Fleetbase\Pod\Services\ConflictService;
use Fleetbase\Pod\Services\FieldSyncService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Supervisor review of sync conflicts:
 *
 *   GET  conflicts                 ?status=open|resolved|all
 *   GET  conflicts/{id}            the record and what it clashed with
 *   POST conflicts/{id}/resolve    {resolution: written_off|recovered|reviewed, notes}
 *   GET  media/{client_media_id}   a photo or signature uploaded from a phone
 */
class ConflictController extends PodController
{
    public function __construct(private ConflictService $conflicts)
    {
    }

    public function index(Request $request)
    {
        $status = $request->input('status', 'open');
        if (!in_array($status, ['open', 'resolved', 'all'], true)) {
            return $this->error('status must be open, resolved or all.');
        }

        return response()->json(['conflicts' => $this->conflicts->list($this->companyUuid(), $status)]);
    }

    public function show(string $id)
    {
        return response()->json(['conflict' => $this->conflicts->detail($this->conflict($id))]);
    }

    public function resolve(Request $request, string $id)
    {
        $conflict = $this->conflict($id);
        $data     = $request->validate([
            'resolution' => ['required', Rule::in(ConflictService::RESOLUTIONS)],
            'notes'      => ['required', 'string', 'max:2000'],
        ]);

        return $this->guarded(fn () => response()->json(['conflict' => $this->conflicts->detail($this->conflicts->resolve($conflict, $data['resolution'], $data['notes'], $this->userUuid()))]));
    }

    public function media(string $id, FieldSyncService $field)
    {
        $media = Media::where('company_uuid', $this->companyUuid())->where('client_media_id', $id)->firstOrFail();

        return response($field->read($media), 200, [
            'Content-Type'           => $media->mime,
            'Cache-Control'          => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function conflict(string $id): SyncConflict
    {
        return SyncConflict::where('company_uuid', $this->companyUuid())->where('uuid', $id)->with('event')->firstOrFail();
    }
}
