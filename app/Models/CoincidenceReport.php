<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CoincidenceReport extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'institution_id',
        'client_record_id',
        'match_details',
    ];

    protected $casts = [
        'match_details' => 'array',
    ];

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }

    public function clientRecord()
    {
        return $this->belongsTo(ClientRecord::class);
    }
}
