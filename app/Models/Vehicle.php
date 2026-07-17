<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vehicle extends Model
{
    protected $table = 'vehicles';

    protected $fillable = [
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
}
