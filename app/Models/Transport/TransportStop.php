<?php

namespace App\Models\Transport;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransportStop extends Model
{
    protected $table = 'transport_stops';

    protected $fillable = [
        'transport_route_id',
        'name',
        'latitude',
        'longitude',
        'sequence',
        'pickup_time',
        'dropoff_time',
        'geofence_radius',
        'status',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'sequence' => 'integer',
        'geofence_radius' => 'integer',
        'status' => 'string',
    ];

    public function route(): BelongsTo
    {
        return $this->belongsTo(TransportRoute::class, 'transport_route_id');
    }
}
