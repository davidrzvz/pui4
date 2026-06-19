<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PuiReport extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'institution_id',
        'client_record_id',
        'curp',
        'external_id',
        'status',
        'match_status',
        'matched_csv_import_batch_id',
        'request_payload',
        'response_payload',
        'match_checked_at',
        'activated_at',
        'deactivated_at',
        'is_test',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
        'match_checked_at' => 'datetime',
        'activated_at' => 'datetime',
        'deactivated_at' => 'datetime',
        'is_test' => 'boolean',
    ];

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function clientRecord()
    {
        return $this->belongsTo(ClientRecord::class);
    }

    public function csvImportBatch()
    {
        return $this->belongsTo(CsvImportBatch::class, 'matched_csv_import_batch_id');
    }

    public function matchChecks()
    {
        return $this->hasMany(PuiReportMatchCheck::class)->orderBy('created_at', 'desc');
    }
}
