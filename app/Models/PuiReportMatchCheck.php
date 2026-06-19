<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PuiReportMatchCheck extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'pui_report_id',
        'institution_id',
        'csv_import_batch_id',
        'client_record_id',
        'match_status',
        'checked_at',
        'checked_by',
        'notes',
    ];

    protected $casts = [
        'checked_at' => 'datetime',
    ];

    public function puiReport()
    {
        return $this->belongsTo(PuiReport::class);
    }

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function csvImportBatch()
    {
        return $this->belongsTo(CsvImportBatch::class);
    }

    public function clientRecord()
    {
        return $this->belongsTo(ClientRecord::class);
    }

    public function checker()
    {
        return $this->belongsTo(User::class, 'checked_by');
    }
}
