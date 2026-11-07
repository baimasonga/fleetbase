<?php

namespace Fleetbase\Pod\Models;

use Fleetbase\Models\Model;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;

class ImportBatch extends Model
{
    use HasUuid;
    use HasPublicId;

    protected $table = 'pod_import_batches';

    protected $publicIdType = 'import';

    protected $fillable = [
        'company_uuid', 'programme_uuid', 'original_filename', 'disk', 'path', 'extension', 'file_hash',
        'sheet', 'header_row', 'mapping', 'is_template', 'status', 'summary', 'issue_counts',
        'created_by_uuid', 'committed_at', 'committed_by_uuid',
    ];

    protected $casts = [
        'header_row'   => 'integer',
        'mapping'      => 'array',
        'is_template'  => 'boolean',
        'summary'      => 'array',
        'issue_counts' => 'array',
        'committed_at' => 'datetime',
    ];

    protected $hidden = ['disk', 'path'];

    public function programme()
    {
        return $this->belongsTo(Programme::class, 'programme_uuid', 'uuid');
    }

    public function rows()
    {
        return $this->hasMany(ImportRow::class, 'import_batch_uuid', 'uuid');
    }
}
