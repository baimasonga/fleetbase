<?php

namespace Fleetbase\Pod\Http\Controllers\Internal\v1;

use Dompdf\Dompdf;
use Dompdf\Options;
use Fleetbase\Pod\Models\DistributionEvent;
use Fleetbase\Pod\Models\Handover;
use Fleetbase\Pod\Services\DistributionService;
use Fleetbase\Pod\Services\StockLedger;
use Fleetbase\Pod\Support\Distribution\EventReportRenderer;
use Illuminate\Http\Request;

/**
 *   GET  programmes/{id}/events          distribution days and routes, ?status=
 *   POST programmes/{id}/events          {kind: point|route, site, name, scheduled_date}
 *   GET  events/{id}                     with stock and totals
 *   POST events/{id}/assign              {admin_3 | village | beneficiaries: [...]}
 *   POST events/{id}/open                {stock: [{sku, lot, quantity}], witnesses: [{name, role}]}
 *   POST events/{id}/handovers           a handover (see DistributionService::handover)
 *   GET  events/{id}/handovers
 *   POST events/{id}/attempts            a failed door-to-door attempt {beneficiary, reason, ...}
 *   POST events/{id}/reassign            {beneficiary, to_event}
 *   POST events/{id}/discrepancies       {sku, lot, quantity, reason, notes}
 *   POST events/{id}/close               {stock: [...], witnesses: [...]}
 *   GET  events/{id}/report              the day report as JSON, or ?format=pdf
 */
class DistributionEventController extends PodController
{
    public function __construct(private DistributionService $distribution)
    {
    }

    public function index(Request $request, string $id)
    {
        $programme = $this->programme($id);
        $events    = DistributionEvent::where('programme_uuid', $programme->uuid)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->with('site:uuid,public_id,name,type')
            ->orderByDesc('scheduled_date')->paginate(min(100, (int) $request->input('limit', 25)));

        return response()->json($events);
    }

    public function store(Request $request, string $id)
    {
        $programme = $this->programme($id);
        $data      = $request->validate([
            'kind'           => ['required', 'in:point,route'],
            'site'           => ['required', 'string'],
            'name'           => ['nullable', 'string', 'max:191'],
            'scheduled_date' => ['nullable', 'date'],
        ]);

        return $this->guarded(fn () => response()->json(['event' => $this->distribution->create($programme, $data)], 201));
    }

    public function show(string $id, StockLedger $stock)
    {
        $event = $this->event($id)->load('site');

        return response()->json([
            'event'  => $event,
            'stock'  => $event->status === DistributionEvent::OPEN ? $stock->onHand('event', $event->uuid) : [],
            'totals' => $event->totals ?? $this->distribution->totals($event),
        ]);
    }

    public function assign(Request $request, string $id)
    {
        $event = $this->event($id);
        $data  = $request->validate([
            'admin_2'         => ['nullable', 'string'],
            'admin_3'         => ['nullable', 'string'],
            'admin_4'         => ['nullable', 'string'],
            'village'         => ['nullable', 'string'],
            'beneficiaries'   => ['nullable', 'array'],
            'beneficiaries.*' => ['string'],
        ]);

        return $this->guarded(fn () => response()->json($this->distribution->assign($event, $data)));
    }

    public function open(Request $request, string $id)
    {
        $event = $this->event($id);
        $data  = $request->validate($this->countAndWitnessRules());

        return $this->guarded(fn () => response()->json(['event' => $this->distribution->open($event, $data['stock'], $data['witnesses'], $this->userUuid())]));
    }

    public function handover(Request $request, string $id)
    {
        $event = $this->event($id);
        $data  = $request->validate([
            'client_handover_id'         => ['nullable', 'string', 'max:64'],
            'card_code'                  => ['nullable', 'string', 'max:255'],
            'beneficiary'                => ['nullable', 'string'],
            'received_by'                => ['nullable', 'in:self,household_member,proxy'],
            'recipient_name'             => ['nullable', 'string', 'max:191'],
            'identity'                   => ['nullable', 'array'],
            'identity.face_matched'      => ['nullable', 'boolean'],
            'identity.id_last4'          => ['nullable', 'string', 'max:8'],
            'identity.photo_taken'       => ['nullable', 'boolean'],
            'identity.witness_confirmed' => ['nullable', 'boolean'],
            'lines'                      => ['required', 'array', 'min:1'],
            'lines.*.sku'                => ['required', 'string'],
            'lines.*.lot'                => ['nullable', 'string', 'max:64'],
            'lines.*.quantity'           => ['required', 'numeric'],
            'evidence'                   => ['nullable', 'array'],
            'evidence.*.type'            => ['required', 'string', 'max:32'],
            'latitude'                   => ['nullable', 'numeric', 'between:-90,90'],
            'longitude'                  => ['nullable', 'numeric', 'between:-180,180'],
            'captured_at'                => ['nullable', 'date'],
        ]);

        return $this->guarded(function () use ($event, $data) {
            $result = $this->distribution->handover($event, $data, $this->userUuid());

            return response()->json($result, $result['replayed'] ? 200 : 201);
        });
    }

    public function handovers(string $id)
    {
        $event = $this->event($id);

        return response()->json(['handovers' => Handover::where('event_uuid', $event->uuid)->with('beneficiary:uuid,public_id,name,sex,village')->orderBy('id')->get()]);
    }

    public function failAttempt(Request $request, string $id)
    {
        $event = $this->event($id);
        $data  = $request->validate([
            'client_handover_id' => ['nullable', 'string', 'max:64'],
            'beneficiary'        => ['required', 'string'],
            'reason'             => ['required', 'string'],
            'evidence'           => ['nullable', 'array'],
            'latitude'           => ['nullable', 'numeric', 'between:-90,90'],
            'longitude'          => ['nullable', 'numeric', 'between:-180,180'],
            'captured_at'        => ['nullable', 'date'],
        ]);

        return $this->guarded(fn () => response()->json($this->distribution->fail($event, $data, $this->userUuid()), 201));
    }

    public function reassign(Request $request, string $id)
    {
        $event = $this->event($id);
        $data  = $request->validate(['beneficiary' => ['required', 'string'], 'to_event' => ['required', 'string']]);
        $to    = $this->event($data['to_event']);

        return $this->guarded(fn () => response()->json(['moved' => $this->distribution->reassign($event, $data['beneficiary'], $to)]));
    }

    public function discrepancy(Request $request, string $id)
    {
        $event = $this->event($id);
        $data  = $request->validate([
            'sku'          => ['required', 'string'],
            'lot'          => ['nullable', 'string', 'max:64'],
            'quantity'     => ['required', 'numeric'],
            'reason'       => ['required', 'string'],
            'liable_party' => ['nullable', 'string'],
            'notes'        => ['required', 'string'],
            'evidence'     => ['nullable', 'array'],
        ]);

        return $this->guarded(fn () => response()->json(['discrepancy' => $this->distribution->discrepancy($event, $data, $this->userUuid())], 201));
    }

    public function close(Request $request, string $id)
    {
        $event = $this->event($id);
        $data  = $request->validate(['stock' => ['present', 'array']] + array_diff_key($this->countAndWitnessRules(), ['stock' => 1]));

        return $this->guarded(fn () => response()->json(['event' => $this->distribution->close($event, $data['stock'], $data['witnesses'], $this->userUuid())]));
    }

    public function report(Request $request, string $id)
    {
        $event  = $this->event($id);
        $report = $this->distribution->report($event);

        if ($request->input('format') !== 'pdf') {
            return response()->json($report);
        }

        $dompdf = new Dompdf(new Options(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'DejaVu Sans']));
        $dompdf->loadHtml((new EventReportRenderer())->render($report), 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        $filename = 'Distribution report - ' . preg_replace('/[^A-Za-z0-9 _-]/', '', $event->name) . ' - ' . $event->public_id . '.pdf';

        return response((string) $dompdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="' . $filename . '"']);
    }

    private function countAndWitnessRules(): array
    {
        return [
            'stock'              => ['required', 'array', 'min:1'],
            'stock.*.sku'        => ['required', 'string'],
            'stock.*.lot'        => ['nullable', 'string', 'max:64'],
            'stock.*.quantity'   => ['required', 'numeric', 'min:0'],
            'witnesses'          => ['required', 'array', 'min:1'],
            'witnesses.*.name'   => ['required', 'string', 'max:191'],
            'witnesses.*.role'   => ['required', 'string'],
            'witnesses.*.signature' => ['nullable', 'string', 'max:64'],
        ];
    }
}
