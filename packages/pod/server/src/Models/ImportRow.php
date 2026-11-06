<?php

namespace Fleetbase\Pod\Models;

use Illuminate\Database\Eloquent\Model;

class ImportRow extends Model
{
    protected $table = 'pod_import_rows';

    protected $fillable = [
        'import_batch_uuid', 'row_number', 'status', 'decision', 'data', 'issues', 'places', 'raw',
        'duplicate_of_row', 'duplicate_of_beneficiary_uuid', 'review', 'beneficiary_uuid', 'decided_by_uuid',
    ];

    protected $casts = [
        'row_number'       => 'integer',
        'duplicate_of_row' => 'integer',
        'data'             => 'array',
        'issues'           => 'array',
        'places'           => 'array',
        'raw'              => 'array',
        'review'           => 'array',
    ];

    /**
     * The raw row can hold a full ID number as typed in the file. It's kept
     * only so the fix-up screen can show what was there; mask it on output.
     */
    public function toArray()
    {
        $array = parent::toArray();
        if (isset($array['raw']['id_number']) && $array['raw']['id_number'] !== '') {
            $array['raw']['id_number'] = '****' . substr(preg_replace('/[^A-Za-z0-9]/', '', $array['raw']['id_number']), -4);
        }
        unset($array['data']['id_hash']);

        return $array;
    }

    public function batch()
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_uuid', 'uuid');
    }
}
