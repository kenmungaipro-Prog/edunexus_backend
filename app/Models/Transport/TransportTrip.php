<?php

namespace App\Models\Transport;

use App\Models\Driver;
use App\Models\Vehicle;
use App\Models\Transport\TripStudent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransportTrip extends Model
{
    protected $table = 'transport_trips';

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_READY = 'ready';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'school_id',
        'transport_route_id',
        'vehicle_id',
        'driver_id',
        'direction',
        'scheduled_start',
        'actual_start',
        'actual_end',
        'start_time',
        'end_time',
        'status',
        'notes',
    ];

    protected $casts = [
        'scheduled_start' => 'datetime',
        'actual_start' => 'datetime',
        'actual_end' => 'datetime',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
    ];

    public function route(): BelongsTo
    {
        return $this->belongsTo(TransportRoute::class, 'transport_route_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(TripLocation::class, 'transport_trip_id');
    }

    public function tripStops(): HasMany
    {
        return $this->hasMany(TripStop::class, 'transport_trip_id')->orderBy('sequence');
    }

    public function tripStudents(): HasMany
    {
        return $this->hasMany(TripStudent::class, 'transport_trip_id');
    }
}
