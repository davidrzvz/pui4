<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GovernmentApiLog extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'institution_id',
        'endpoint',
        'method',
        'status_code',
        'request_data',
        'response_data',
        'ip_address',
    ];

    protected $casts = [
        'request_data' => 'array',
        'response_data' => 'array',
    ];

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }
}
