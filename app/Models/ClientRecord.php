<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientRecord extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'institution_id',
        'csv_import_batch_id',
        'curp',
        'internal_identifier',
        'name',
        'is_active',
    ];

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function csvImportBatch()
    {
        return $this->belongsTo(CsvImportBatch::class);
    }

    public function coincidenceReports()
    {
        return $this->hasMany(CoincidenceReport::class);
    }

    protected static function booted()
    {
        static::created(function ($record) {
            self::runMatching($record, 'created');
        });

        static::updated(function ($record) {
            self::runMatching($record, 'updated');
        });
    }

    private static function runMatching($record, $event)
    {
        $service = app(\App\Services\PuiMatchingService::class);
        $stats = $service->refreshPendingReports($record->institution_id);
        request()->attributes->set('pui_matching_stats', $stats);
    }
}
