<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CsvImportBatch extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'institution_id',
        'user_id',
        'filename',
        'status',
        'total_records',
        'processed_records',
        'created_records',
        'updated_records',
        'failed_records',
        'duplicate_records',
        'error_summary',
    ];

    protected $casts = [
        'error_summary' => 'array',
    ];

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
