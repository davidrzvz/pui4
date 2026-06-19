<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Institution extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'rfc',
        'name',
        'pui_credentials',
        'is_active',
    ];

    protected $casts = [
        'pui_credentials' => 'array',
        'is_active' => 'boolean',
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function clientRecords()
    {
        return $this->hasMany(ClientRecord::class);
    }

    public function csvImportBatches()
    {
        return $this->hasMany(CsvImportBatch::class);
    }

    public function puiReports()
    {
        return $this->hasMany(PuiReport::class);
    }

    public function coincidenceReports()
    {
        return $this->hasMany(CoincidenceReport::class);
    }
}
