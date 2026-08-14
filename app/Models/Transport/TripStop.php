<?php

namespace App\Models\Transport;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TripStop extends Model
{
    protected $table = 'trip_stops';

    protected $fillable = [
        'transport_trip_id',
        'transport_stop_id',
        'sequence',
        'name',
        'latitude',
        'longitude',
        'pickup_time',
        'dropoff_time',
        'arrived_at',
        'departed_at',
        'status',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'sequence' => 'integer',
        'arrived_at' => 'datetime',
        'departed_at' => 'datetime',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(TransportTrip::class, 'transport_trip_id');
    }

    public function stop(): BelongsTo
    {
        return $this->belongsTo(TransportStop::class, 'transport_stop_id');
    }
}
