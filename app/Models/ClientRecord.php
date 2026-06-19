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
}
