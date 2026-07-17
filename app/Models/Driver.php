<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Driver extends Model
{
    protected $table = 'drivers';

    protected $fillable = [
        'name',
        'phone',
        'license_no',
        'license_expiry',
        'status',
    ];

    protected $casts = [
        'license_expiry' => 'date',
    ];

    // Relationships
    public function transportRoutes(): HasMany
    {
        return $this->hasMany(TransportRoute::class);
    }
}
