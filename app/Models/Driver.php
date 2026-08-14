<?php

namespace App\Models;

use App\Models\Transport\TransportRoute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

use App\Models\School;

class Driver extends Model
{
    protected $table = 'drivers';

    protected $fillable = [
        'school_id',
        'user_id',
        'name',
        'phone',
        'license_no',
        'license_expiry',
        'status',
    ];

    protected $casts = [
        'school_id' => 'integer',
        'license_expiry' => 'date',
    ];

    // Relationships
    public function transportRoutes(): HasMany
    {
        return $this->hasMany(\App\Models\Transport\TransportRoute::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function vehicleAssignments(): HasMany
    {
        return $this->hasMany(\App\Models\Transport\VehicleDriverAssignment::class, 'driver_id');
    }

    public function activeVehicleAssignment(): HasOne
    {
        return $this->hasOne(\App\Models\Transport\VehicleDriverAssignment::class, 'driver_id')
            ->where('status', 'active')
            ->whereNull('ended_at');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
