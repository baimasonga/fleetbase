<?php

namespace Fleetbase\Pod\Http\Controllers\Internal\v1;

use Fleetbase\Pod\Models\TransportLeg;
use Fleetbase\Pod\Services\TransportService;
use Illuminate\Http\Request;

/**
 *   GET  programmes/{id}/legs     truck trips, ?status=
 *   POST programmes/{id}/legs     {origin, destination, transporter_kind, transporter_name, vehicle_plate, waybill_no, ...}
 *   GET  legs/{id}                with lines and discrepancies
 *   POST legs/{id}/load           {lines: [{sku, lot, quantity}], seals: [...], restricted_segregated}
 *   POST legs/{id}/depart
 *   POST legs/{id}/arrive
 *   POST legs/{id}/receive        {lines: [{sku, lot, good, damaged, note}], seals: [{number, intact}]}
 *   POST legs/{id}/close          {seal_explanation}
 *   POST legs/{id}/cancel
 *   POST discrepancies/{id}/resolve {notes, resolution, reason?, liable_party?, evidence?}
 */
class TransportLegController extends PodController
{
    public function __construct(private TransportService $transport)
    {
    }

    public function index(Request $request, string $id)
    {
        $programme = $this->programme($id);
        $legs      = TransportLeg::where('programme_uuid', $programme->uuid)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->with(['origin:uuid,public_id,name', 'destination:uuid,public_id,name'])
            ->orderByDesc('created_at')->paginate(min(100, (int) $request->input('limit', 25)));

        return response()->json($legs);
    }

    public function store(Request $request, string $id)
    {
        $programme = $this->programme($id);
        $data      = $request->validate([
            'origin'                => ['required', 'string'],
            'destination'           => ['required', 'string'],
            'transporter_kind'      => ['nullable', 'in:staff,hired'],
            'transporter_name'      => ['nullable', 'string', 'max:191'],
            'transporter_phone'     => ['nullable', 'string', 'max:20'],
            'vehicle_plate'         => ['nullable', 'string', 'max:32'],
            'waybill_no'            => ['nullable', 'string', 'max:64'],
            'expected_duration_min' => ['nullable', 'integer', 'min:1'],
            'order_uuid'            => ['nullable', 'string', 'size:36'],
            'driver_uuid'           => ['nullable', 'string', 'size:36'],
            'vehicle_uuid'          => ['nullable', 'string', 'size:36'],
            'vendor_uuid'           => ['nullable', 'string', 'size:36'],
            'notes'                 => ['nullable', 'string'],
        ]);

        return $this->guarded(fn () => response()->json(['leg' => $this->transport->create($programme, $data, $this->userUuid())], 201));
    }

    public function show(string $id)
    {
        return response()->json(['leg' => $this->leg($id)->load(['lines', 'discrepancies', 'origin', 'destination'])]);
    }

    public function load(Request $request, string $id)
    {
        $leg  = $this->leg($id);
        $data = $request->validate([
            'lines'                 => ['required', 'array', 'min:1'],
            'lines.*.sku'           => ['required', 'string'],
            'lines.*.lot'           => ['nullable', 'string', 'max:64'],
            'lines.*.expiry'        => ['nullable', 'date'],
            'lines.*.quantity'      => ['required', 'numeric'],
            'seals'                 => ['nullable', 'array'],
            'seals.*'               => ['string', 'max:64'],
            'restricted_segregated' => ['nullable', 'boolean'],
        ]);

        return $this->guarded(fn () => response()->json(['leg' => $this->transport->load($leg, $data['lines'], $data['seals'] ?? [], $data['restricted_segregated'] ?? null, $this->userUuid())->load('lines')]));
    }

    public function depart(string $id)
    {
        $leg = $this->leg($id);

        return $this->guarded(fn () => response()->json(['leg' => $this->transport->depart($leg)]));
    }

    public function arrive(string $id)
    {
        $leg = $this->leg($id);

        return $this->guarded(fn () => response()->json(['leg' => $this->transport->arrive($leg)]));
    }

    public function receive(Request $request, string $id)
    {
        $leg  = $this->leg($id);
        $data = $request->validate([
            'lines'           => ['required', 'array', 'min:1'],
            'lines.*.sku'     => ['required', 'string'],
            'lines.*.lot'     => ['nullable', 'string', 'max:64'],
            'lines.*.good'    => ['required', 'numeric', 'min:0'],
            'lines.*.damaged' => ['nullable', 'numeric', 'min:0'],
            'lines.*.note'    => ['nullable', 'string', 'max:191'],
            'seals'           => ['nullable', 'array'],
            'seals.*.number'  => ['required', 'string', 'max:64'],
            'seals.*.intact'  => ['required', 'boolean'],
        ]);

        return $this->guarded(fn () => response()->json(['leg' => $this->transport->receive($leg, $data['lines'], $data['seals'] ?? [], $this->userUuid())->load(['lines', 'discrepancies'])]));
    }

    public function close(Request $request, string $id)
    {
        $leg = $this->leg($id);

        return $this->guarded(fn () => response()->json(['leg' => $this->transport->close($leg, $request->input('seal_explanation'), $this->userUuid())]));
    }

    public function cancel(string $id)
    {
        $leg = $this->leg($id);

        return $this->guarded(fn () => response()->json(['leg' => $this->transport->cancel($leg, $this->userUuid())]));
    }

    public function resolveDiscrepancy(Request $request, string $id)
    {
        $discrepancy = $this->discrepancyRecord($id);

        return $this->guarded(fn () => response()->json(['discrepancy' => $this->transport->resolveDiscrepancy($discrepancy, $request->only(['notes', 'resolution', 'reason', 'liable_party', 'evidence']), $this->userUuid())]));
    }
}
