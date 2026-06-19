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
