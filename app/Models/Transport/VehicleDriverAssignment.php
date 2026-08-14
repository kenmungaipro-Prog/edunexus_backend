<?php

namespace App\Models\Transport;

use App\Models\Driver;
use App\Models\School;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleDriverAssignment extends Model
{
    protected $table = 'vehicle_driver_assignments';

    protected $fillable = [
        'school_id',
        'vehicle_id',
        'driver_id',
        'started_at',
        'ended_at',
        'status',
        'notes',
    ];

    protected $casts = [
        'school_id' => 'integer',
        'vehicle_id' => 'integer',
        'driver_id' => 'integer',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->ended_at === null;
    }
}
