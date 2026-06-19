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
        'status',
        'request_payload',
        'response_payload',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
    ];

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }
}
