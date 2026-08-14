<?php

namespace App\Models;

use App\Models\Transport\TransportRoute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

use App\Models\School;

class Vehicle extends Model
{
    protected $table = 'vehicles';

    protected $fillable = [
        'school_id',
        'registration_number',
        'make',
        'model',
        'capacity',
        'status',
        'last_lat',
        'last_lng',
        'last_speed',
        'location_updated_at',
    ];

    protected $casts = [
        'school_id' => 'integer',
        'capacity' => 'integer',
        'last_lat' => 'float',
        'last_lng' => 'float',
        'last_speed' => 'integer',
        'location_updated_at' => 'datetime',
    ];

    // Relationships
    public function transportRoutes(): HasMany
    {
        return $this->hasMany(TransportRoute::class);
    }

    public function currentRoute()
    {
        // A vehicle is assigned to a specific transport route
        return $this->hasOne(TransportRoute::class, 'vehicle_id');
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function driverAssignments(): HasMany
    {
        return $this->hasMany(\App\Models\Transport\VehicleDriverAssignment::class, 'vehicle_id');
    }

    public function activeDriverAssignment(): HasOne
    {
        return $this->hasOne(\App\Models\Transport\VehicleDriverAssignment::class, 'vehicle_id')
            ->where('status', 'active')
            ->whereNull('ended_at');
    }

    public function telemetry(): HasMany
    {
        return $this->hasMany(VehicleTelemetry::class);
    }
}
