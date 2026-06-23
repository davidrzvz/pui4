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
        'pui_report_id',
        'endpoint',
        'method',
        'request_data',
        'response_data',
        'status_code',
        'ip_address',
        'operator_user_id',
    ];

    protected $casts = [
        'request_data' => 'array',
        'response_data' => 'array',
    ];

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function operator()
    {
        return $this->belongsTo(User::class, 'operator_user_id');
    }

    public function puiReport()
    {
        return $this->belongsTo(PuiReport::class);
    }
}
