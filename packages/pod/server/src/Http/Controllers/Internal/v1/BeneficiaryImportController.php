<?php

namespace Fleetbase\Pod\Http\Controllers\Internal\v1;

use Fleetbase\Pod\Models\ImportRow;
use Fleetbase\Pod\Services\AdminAreaRepository;
use Fleetbase\Pod\Services\BeneficiaryImportService;
use Fleetbase\Pod\Support\Countries\CountryProfiles;
use Fleetbase\Pod\Support\Import\ColumnMapper;
use Fleetbase\Pod\Support\Import\FarmerListTemplate;
use Fleetbase\Pod\Support\Import\ImportPlan;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Beneficiary list import (AGRI-INPUTS.md §7.1):
 *
 *   GET  programmes/{id}/farmer-list-template   download the .xlsx template
 *   POST programmes/{id}/imports                upload a list -> sheets, headers, suggested mapping
 *   GET  imports/{id}                           the batch and its summary
 *   POST imports/{id}/preview                   mapping (+ sheet, header_row) -> per-row plan, nothing created
 *   GET  imports/{id}/rows?status=review        rows, for the fix-up and review screens
 *   POST imports/{id}/rows/{row}/decision       {"decision": "import"|"skip"} for a likely duplicate
 *   POST imports/{id}/commit                    create the beneficiaries
 */
class BeneficiaryImportController extends PodController
{
    public function __construct(private BeneficiaryImportService $imports)
    {
    }

    public function template(string $id, CountryProfiles $countries, AdminAreaRepository $areas)
    {
        $programme = $this->programme($id);
        $country   = $countries->get($programme->country);
        $book      = (new FarmerListTemplate())->build($country, $areas->forCountry($country->code));
        $filename  = 'Farmer List Template - ' . $country->name . '.xlsx';

        return response()->streamDownload(function () use ($book) {
            (new Xlsx($book))->save('php://output');
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function upload(Request $request, string $id)
    {
        $programme = $this->programme($id);
        $request->validate(['file' => ['required', 'file', 'max:' . (int) config('pod.import.max_upload_kb', 20480)]]);

        try {
            $result = $this->imports->upload($programme, $request->file('file'), $this->userUuid());
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->error($e->getMessage());
        }

        return response()->json($result + ['fields' => array_keys(ColumnMapper::FIELDS)], 201);
    }

    public function show(string $id)
    {
        return response()->json(['import' => $this->batch($id)]);
    }

    public function preview(Request $request, string $id)
    {
        $batch = $this->batch($id);
        $data  = $request->validate([
            'mapping'    => ['nullable', 'array'],
            'mapping.*'  => ['string'],
            'sheet'      => ['nullable', 'string'],
            'header_row' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        try {
            $plan = $this->imports->preview($batch, $data['mapping'] ?? null, $data['sheet'] ?? null, $data['header_row'] ?? 1);
        } catch (\InvalidArgumentException|\LogicException|\RuntimeException $e) {
            return $this->error($e->getMessage());
        }

        return response()->json([
            'import'       => $batch->fresh(),
            'summary'      => $plan->summary(),
            'issue_counts' => $plan->issueCounts(),
        ]);
    }

    public function rows(Request $request, string $id)
    {
        $batch = $this->batch($id);
        $rows  = ImportRow::where('import_batch_uuid', $batch->uuid)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->orderBy('row_number')
            ->paginate(min(200, (int) $request->input('limit', 50)));

        return response()->json($rows);
    }

    public function decide(Request $request, string $id, int $row)
    {
        $batch    = $this->batch($id);
        $decision = $request->validate(['decision' => ['required', 'in:import,skip']])['decision'];

        try {
            $updated = $this->imports->decide($batch, $row, $decision, $this->userUuid());
        } catch (\InvalidArgumentException|\LogicException $e) {
            return $this->error($e->getMessage());
        }

        return response()->json(['row' => $updated]);
    }

    public function commit(Request $request, string $id)
    {
        $batch = $this->batch($id);

        try {
            $result = $this->imports->commit($batch, $this->userUuid(), $request->boolean('skip_undecided'));
        } catch (\LogicException $e) {
            return $this->error($e->getMessage());
        }

        return response()->json($result + ['import' => $batch->fresh(), 'review_status' => ImportPlan::REVIEW]);
    }
}
