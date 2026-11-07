<?php

namespace Fleetbase\Pod\Http\Controllers\Internal\v1;

use Fleetbase\Pod\Models\Site;
use Fleetbase\Pod\Services\StockLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 *   GET  sites                    ?type=
 *   POST sites                    a store, distribution point or vehicle
 *   GET  sites/{id}               with stock on hand
 *   POST sites/{id}/stock/receive stock arriving from a supplier (central warehouse)
 */
class SiteController extends PodController
{
    public function index(Request $request)
    {
        $sites = Site::where('company_uuid', $this->companyUuid())
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->orderBy('type')->orderBy('name')->get();

        return response()->json(['sites' => $sites]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'              => ['required', 'string', 'max:191'],
            'type'              => ['required', Rule::in(Site::TYPES)],
            'country'           => ['nullable', 'string', 'size:2'],
            'admin_1'           => ['nullable', 'string', 'max:32'],
            'admin_2'           => ['nullable', 'string', 'max:32'],
            'admin_3'           => ['nullable', 'string', 'max:32'],
            'admin_4'           => ['nullable', 'string', 'max:32'],
            'village'           => ['nullable', 'string', 'max:191'],
            'latitude'          => ['nullable', 'numeric', 'between:-90,90'],
            'longitude'         => ['nullable', 'numeric', 'between:-180,180'],
            'geofence_radius_m' => ['nullable', 'integer', 'min:50', 'max:20000'],
            'place_uuid'        => ['nullable', 'string', 'size:36'],
            'vehicle_uuid'      => ['nullable', 'string', 'size:36'],
        ]);

        $site = Site::create($data + ['company_uuid' => $this->companyUuid(), 'country' => strtoupper($data['country'] ?? config('pod.default_country', 'SL')), 'active' => true]);

        return response()->json(['site' => $site], 201);
    }

    public function show(string $id, StockLedger $stock)
    {
        $site = $this->site($id);

        return response()->json(['site' => $site, 'stock' => $stock->onHand('site', $site->uuid)]);
    }

    public function receive(Request $request, string $id, StockLedger $stock)
    {
        $site = $this->site($id);
        $data = $request->validate([
            'programme'        => ['required', 'string'],
            'reference'        => ['required', 'string', 'max:191'], // supplier delivery note or GRN number
            'lines'            => ['required', 'array', 'min:1'],
            'lines.*.sku'      => ['required', 'string'],
            'lines.*.lot'      => ['nullable', 'string', 'max:64'],
            'lines.*.expiry'   => ['nullable', 'date'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);
        $programme = $this->programme($data['programme']);

        return $this->guarded(function () use ($site, $programme, $data, $stock) {
            $skus    = $programme->items()->pluck('sku')->all();
            $unknown = array_diff(array_column($data['lines'], 'sku'), $skus);
            if ($unknown) {
                throw new \InvalidArgumentException('Not in the programme\'s item list: ' . implode(', ', $unknown) . '.');
            }

            DB::transaction(function () use ($site, $programme, $data, $stock) {
                foreach ($data['lines'] as $line) {
                    $stock->move([
                        'company_uuid'   => $site->company_uuid,
                        'programme_uuid' => $programme->uuid,
                        'holder_type'    => 'site',
                        'holder_uuid'    => $site->uuid,
                        'sku'            => $line['sku'],
                        'lot'            => (string) ($line['lot'] ?? ''),
                        'expiry'         => $line['expiry'] ?? null,
                        'quantity'       => (float) $line['quantity'],
                        'reason'         => 'received_from_supplier',
                        'user_uuid'      => $this->userUuid(),
                        'note'           => $data['reference'],
                    ]);
                }
            });

            return response()->json(['site' => $site, 'stock' => $stock->onHand('site', $site->uuid)], 201);
        });
    }
}
