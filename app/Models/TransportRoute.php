<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class TransportRoute extends Model
{
    protected $table = 'transport_routes';

    protected $fillable = [
        'school_id',
        'name',
        'vehicle_id',
        'driver_id',
        'stops',
        'monthly_fee',
    ];

    protected $casts = [
        'stops' => 'array',
        'monthly_fee' => 'decimal:2',
    ];

    // Relationships
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

    public function transportAssignments(): HasMany
    {
        return $this->hasMany(TransportAssignment::class);
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'transport_assignments')
                    ->withPivot('stop')
                    ->withTimestamps();
    }
}
