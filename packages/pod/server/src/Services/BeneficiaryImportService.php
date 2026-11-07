<?php

namespace Fleetbase\Pod\Services;

use Fleetbase\Pod\Models\Beneficiary;
use Fleetbase\Pod\Models\ImportBatch;
use Fleetbase\Pod\Models\ImportRow;
use Fleetbase\Pod\Models\Programme;
use Fleetbase\Pod\Support\Countries\CountryProfiles;
use Fleetbase\Pod\Support\Import\BeneficiaryNormalizer;
use Fleetbase\Pod\Support\Import\ColumnMapper;
use Fleetbase\Pod\Support\Import\DuplicateDetector;
use Fleetbase\Pod\Support\Import\FarmerListTemplate;
use Fleetbase\Pod\Support\Import\ImportPlan;
use Fleetbase\Pod\Support\Import\ImportPlanner;
use Fleetbase\Pod\Support\Import\SheetData;
use Fleetbase\Pod\Support\Import\SpreadsheetReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Imports beneficiary lists (AGRI-INPUTS.md §7.1 step 1):
 *
 *   upload()  store the file, list its sheets, suggest a column mapping
 *   preview() read the sheet, clean and check every row, find duplicates;
 *             saves the plan as import rows, creates no beneficiaries
 *   decide()  a person decides each likely duplicate: import or skip
 *   commit()  create beneficiaries from accepted rows and rows decided "import"
 */
class BeneficiaryImportService
{
    public function __construct(
        private CountryProfiles $countries,
        private AdminAreaRepository $areas,
        private SpreadsheetReader $reader = new SpreadsheetReader(),
        private ColumnMapper $mapper = new ColumnMapper(),
    ) {
    }

    /**
     * @return array{batch: ImportBatch, sheets: list<string>, headers: list<string>, sample_rows: array, suggested_mapping: array<string, string>, is_template: bool}
     */
    public function upload(Programme $programme, UploadedFile $file, ?string $userUuid = null): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, SpreadsheetReader::EXTENSIONS, true)) {
            throw new \InvalidArgumentException('Only .xlsx, .xls and .csv files can be imported.');
        }

        $disk = config('pod.import.disk', config('filesystems.default'));
        $path = $file->storeAs('pod/imports/' . $programme->company_uuid, uniqid('import_', true) . '.' . $extension, ['disk' => $disk]);

        $batch = ImportBatch::create([
            'company_uuid'      => $programme->company_uuid,
            'programme_uuid'    => $programme->uuid,
            'original_filename' => $file->getClientOriginalName(),
            'disk'              => $disk,
            'path'              => $path,
            'extension'         => $extension,
            'file_hash'         => hash_file('sha256', $file->getRealPath()),
            'status'            => 'uploaded',
            'created_by_uuid'   => $userUuid,
        ]);

        $local  = $this->localCopy($batch);
        $sheets = $this->reader->sheetNames($local, $extension);
        $sheet  = in_array('Farmers', $sheets, true) ? 'Farmers' : $sheets[0];
        $data   = $this->reader->read($local, $sheet, 1, $extension, (int) config('pod.import.max_rows', 50000));

        $country    = $this->countries->get($programme->country);
        $isTemplate = FarmerListTemplate::matches($country, $data->headers);
        $mapping    = $isTemplate ? FarmerListTemplate::mapping($country) : $this->mapper->suggest($data->headers);

        $batch->update(['sheet' => $sheet, 'is_template' => $isTemplate, 'mapping' => $mapping]);

        // Show a few rows to help check the mapping, with ID numbers masked.
        $sample   = array_slice($data->associativeRows(), 0, 5, true);
        $idHeader = $mapping['id_number'] ?? null;
        foreach ($sample as &$row) {
            if ($idHeader !== null && isset($row[$idHeader]) && (string) $row[$idHeader] !== '') {
                $row[$idHeader] = '****' . substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $row[$idHeader]), -4);
            }
        }
        unset($row);

        return [
            'batch'             => $batch,
            'sheets'            => $sheets,
            'headers'           => $data->headers,
            'sample_rows'       => $sample,
            'suggested_mapping' => $mapping,
            'is_template'       => $isTemplate,
        ];
    }

    /**
     * @param array<string, string>|null $mapping field => header; the saved suggestion when null
     */
    public function preview(ImportBatch $batch, ?array $mapping = null, ?string $sheet = null, int $headerRow = 1): ImportPlan
    {
        if ($batch->status === 'committed') {
            throw new \LogicException('This import has already been committed.');
        }

        $programme = $batch->programme;
        $mapping ??= $batch->mapping ?? [];
        $sheet   ??= $batch->sheet;

        $data = $this->reader->read($this->localCopy($batch), $sheet, $headerRow, $batch->extension, (int) config('pod.import.max_rows', 50000));
        $plan = $this->planner($programme)->plan($data, $mapping, $this->existing($programme));

        DB::transaction(function () use ($batch, $plan, $mapping, $sheet, $headerRow) {
            ImportRow::where('import_batch_uuid', $batch->uuid)->delete();

            $now  = now();
            $rows = [];
            foreach ($plan->rows as $number => $entry) {
                $row    = $entry['row']->toArray();
                $rows[] = [
                    'import_batch_uuid'             => $batch->uuid,
                    'row_number'                    => $number,
                    'status'                        => $entry['status'],
                    'data'                          => json_encode($row['data']),
                    'issues'                        => json_encode($row['issues']),
                    'places'                        => json_encode($row['places']),
                    'raw'                           => json_encode($row['raw']),
                    'duplicate_of_row'              => $entry['duplicate_of_row'],
                    'duplicate_of_beneficiary_uuid' => $entry['duplicate_of_existing'],
                    'review'                        => $entry['review'] ? json_encode($entry['review']) : null,
                    'created_at'                    => $now,
                    'updated_at'                    => $now,
                ];
                if (count($rows) === 500) {
                    ImportRow::insert($rows);
                    $rows = [];
                }
            }
            if ($rows) {
                ImportRow::insert($rows);
            }

            $batch->update([
                'mapping'      => $mapping,
                'sheet'        => $sheet,
                'header_row'   => $headerRow,
                'status'       => 'previewed',
                'summary'      => $plan->summary(),
                'issue_counts' => $plan->issueCounts(),
            ]);
        });

        return $plan;
    }

    /**
     * Decide a likely duplicate: "import" (they're different people) or "skip".
     */
    public function decide(ImportBatch $batch, int $rowNumber, string $decision, ?string $userUuid = null): ImportRow
    {
        if (!in_array($decision, ['import', 'skip'], true)) {
            throw new \InvalidArgumentException('The decision must be "import" or "skip".');
        }

        $row = ImportRow::where('import_batch_uuid', $batch->uuid)->where('row_number', $rowNumber)->firstOrFail();
        if ($row->status !== ImportPlan::REVIEW) {
            throw new \LogicException("Row {$rowNumber} doesn't need a decision.");
        }

        $row->update(['decision' => $decision, 'decided_by_uuid' => $userUuid]);

        return $row;
    }

    /**
     * Create the beneficiaries. Rows still waiting for a decision are left
     * for later, unless $skipUndecided is true.
     *
     * @return array{created: int, waiting: int}
     */
    public function commit(ImportBatch $batch, ?string $userUuid = null, bool $skipUndecided = false): array
    {
        if ($batch->status !== 'previewed') {
            throw new \LogicException('Preview the import before committing it.');
        }

        $waiting = ImportRow::where('import_batch_uuid', $batch->uuid)->where('status', ImportPlan::REVIEW)->whereNull('decision')->count();
        if ($waiting > 0 && !$skipUndecided) {
            throw new \LogicException("{$waiting} likely duplicates still need a decision.");
        }

        $created = 0;
        DB::transaction(function () use ($batch, $userUuid, &$created) {
            $rows = ImportRow::where('import_batch_uuid', $batch->uuid)
                ->where(function ($query) {
                    $query->where('status', ImportPlan::ACCEPTED)
                        ->orWhere(fn ($q) => $q->where('status', ImportPlan::REVIEW)->where('decision', 'import'));
                })
                ->whereNull('beneficiary_uuid')
                ->orderBy('row_number')
                ->lockForUpdate()
                ->get();

            foreach ($rows as $row) {
                $data        = $row->data ?? [];
                $beneficiary = Beneficiary::create([
                    'company_uuid'        => $batch->company_uuid,
                    'programme_uuid'      => $batch->programme_uuid,
                    'farmer_ref'          => $data['farmer_ref'] ?? null,
                    'name'                => $data['name'],
                    'sex'                 => $data['sex'] ?? null,
                    'birth_year'          => $data['birth_year'] ?? null,
                    'phone'               => $data['phone'] ?? null,
                    'phone_extra'         => $data['phone_extra'] ?? null,
                    'id_type'             => $data['id_type'] ?? null,
                    'id_last4'            => $data['id_last4'] ?? null,
                    'id_hash'             => $data['id_hash'] ?? null,
                    'admin_1'             => $data['admin_1'] ?? null,
                    'admin_2'             => $data['admin_2'] ?? null,
                    'admin_3'             => $data['admin_3'] ?? null,
                    'admin_4'             => $data['admin_4'] ?? null,
                    'village'             => $data['village'] ?? null,
                    'group_name'          => $data['group'] ?? null,
                    'household_size'      => $data['household_size'] ?? null,
                    'farm_size'           => $data['farm_size'] ?? null,
                    'crop'                => $data['crop'] ?? null,
                    'notes'               => $data['notes'] ?? null,
                    'latitude'            => $data['latitude'] ?? null,
                    'longitude'           => $data['longitude'] ?? null,
                    'status'              => 'active',
                    'verification_status' => 'unverified',
                    'source'              => [
                        'type'     => 'excel',
                        'file'     => $batch->original_filename,
                        'sheet'    => $batch->sheet,
                        'row'      => $row->row_number,
                        'batch'    => $batch->public_id,
                        'template' => $batch->is_template,
                    ],
                    'import_batch_uuid' => $batch->uuid,
                ]);

                $row->update(['beneficiary_uuid' => $beneficiary->uuid]);
                $created++;
            }

            $batch->update(['status' => 'committed', 'committed_at' => now(), 'committed_by_uuid' => $userUuid]);
        });

        return ['created' => $created, 'waiting' => $waiting];
    }

    private function planner(Programme $programme): ImportPlanner
    {
        $country = $this->countries->get($programme->country);
        $areas   = $this->areas->forCountry($country->code);

        $normalizer = new BeneficiaryNormalizer(
            $country,
            $this->areas->matcher($country->code),
            $areas,
            (string) config('pod.import.id_hash_key'),
            (int) now()->format('Y'),
            config('pod.import', []),
        );

        return new ImportPlanner(
            $this->mapper,
            $normalizer,
            new DuplicateDetector((float) config('pod.import.name_similarity', 0.92), (int) config('pod.import.birth_year_tolerance', 2)),
        );
    }

    /**
     * Beneficiaries already registered in the programme, for duplicate checks.
     *
     * @return array<string, array{data: array, place: ?string}>
     */
    private function existing(Programme $programme): array
    {
        $existing = [];
        Beneficiary::where('programme_uuid', $programme->uuid)
            ->select(['uuid', 'name', 'sex', 'birth_year', 'village', 'phone', 'id_hash', 'admin_1', 'admin_2', 'admin_3', 'admin_4'])
            ->chunk(2000, function ($chunk) use (&$existing) {
                foreach ($chunk as $beneficiary) {
                    $existing[$beneficiary->uuid] = $beneficiary->toDuplicateCandidate();
                }
            });

        return $existing;
    }

    /**
     * PhpSpreadsheet needs a local file; copy it down from remote storage when needed.
     */
    private function localCopy(ImportBatch $batch): string
    {
        $storage = Storage::disk($batch->disk);
        try {
            $path = $storage->path($batch->path);
            if (is_readable($path)) {
                return $path;
            }
        } catch (\Throwable) {
            // Remote disks (S3, MinIO) have no local path.
        }

        $local = tempnam(sys_get_temp_dir(), 'pod_import_') . '.' . $batch->extension;
        file_put_contents($local, $storage->get($batch->path));

        return $local;
    }
}
