<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeofenceEvent extends Model
{
    protected $table = 'geofence_events';

    protected $fillable = [
        'school_id',
        'transport_route_id',
        'transport_geofence_id',
        'vehicle_id',
        'event_type',
        'lat',
        'lng',
        'triggered_at',
        'payload',
    ];

    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
        'triggered_at' => 'datetime',
        'payload' => 'array',
    ];

    public function route(): BelongsTo
    {
        return $this->belongsTo(TransportRoute::class, 'transport_route_id');
    }

    public function geofence(): BelongsTo
    {
        return $this->belongsTo(TransportGeofence::class, 'transport_geofence_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
