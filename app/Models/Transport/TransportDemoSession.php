<?php

namespace App\Models\Transport;

use App\Models\School;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransportDemoSession extends Model
{
    protected $fillable = [
        'session_id',
        'school_id',
        'vehicle_id',
        'started_by',
        'lat',
        'lng',
        'speed',
        'heading',
        'accuracy',
        'position_at',
        'started_at',
        'expires_at',
        'hard_expires_at',
    ];

    protected $casts = [
        'school_id' => 'integer',
        'vehicle_id' => 'integer',
        'started_by' => 'integer',
        'lat' => 'float',
        'lng' => 'float',
        'speed' => 'integer',
        'heading' => 'float',
        'accuracy' => 'float',
        'position_at' => 'datetime',
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
        'hard_expires_at' => 'datetime',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
