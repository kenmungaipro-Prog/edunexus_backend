<?php

namespace App\Models\Transport;

use App\Models\Student;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TripStudent extends Model
{
    protected $table = 'trip_students';

    public const STATUS_PENDING = 'pending';
    public const STATUS_BOARDED = 'boarded';
    public const STATUS_DROPPED_OFF = 'dropped_off';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'school_id',
        'transport_trip_id',
        'student_id',
        'boarding_stop_id',
        'boarding_time',
        'dropoff_stop_id',
        'dropoff_time',
        'status',
    ];

    protected $casts = [
        'boarding_time' => 'datetime',
        'dropoff_time' => 'datetime',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(TransportTrip::class, 'transport_trip_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function boardingStop(): BelongsTo
    {
        return $this->belongsTo(TransportStop::class, 'boarding_stop_id');
    }

    public function dropoffStop(): BelongsTo
    {
        return $this->belongsTo(TransportStop::class, 'dropoff_stop_id');
    }
}
