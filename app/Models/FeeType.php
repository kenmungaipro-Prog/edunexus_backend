<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeeType extends Model
{
    use HasFactory;

    protected $table = 'fee_types';

    protected $fillable = [
        'school_id',
        'name',
        'amount',
        'frequency',
        'description',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    // Relationships
    public function fees(): HasMany
    {
        return $this->hasMany(Fee::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
