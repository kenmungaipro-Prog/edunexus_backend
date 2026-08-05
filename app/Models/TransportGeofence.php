<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransportGeofence extends Model
{
    protected $table = 'transport_geofences';

    protected $fillable = [
        'school_id',
        'transport_route_id',
        'stop_name',
        'stop_type',
        'lat',
        'lng',
        'radius_meters',
        'active',
    ];

    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
        'radius_meters' => 'integer',
        'active' => 'boolean',
    ];

    public function route(): BelongsTo
    {
        return $this->belongsTo(TransportRoute::class, 'transport_route_id');
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
