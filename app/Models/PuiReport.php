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
        'government_sent_at',
        'government_status',
        'government_response',
        'government_error',
        'government_sent_by',
        'sent_evidence',
        'is_test',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
        'match_checked_at' => 'datetime',
        'activated_at' => 'datetime',
        'deactivated_at' => 'datetime',
        'government_sent_at' => 'datetime',
        'government_response' => 'array',
        'sent_evidence' => 'array',
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

    public function governmentSentBy()
    {
        return $this->belongsTo(User::class, 'government_sent_by');
    }

    public function isClosed(): bool
    {
        return in_array($this->status, ['FINALIZADO', 'DESACTIVADO']) || $this->government_status === 'ENVIADO';
    }
}
