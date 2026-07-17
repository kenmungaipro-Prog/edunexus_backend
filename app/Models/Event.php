<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Event extends Model
{
    protected $table = 'events';

    protected $fillable = [
        'school_id',
        'created_by',
        'title',
        'description',
        'event_date',
        'end_date',
        'type',
        'venue',
        'notify_all',
    ];

    protected $casts = [
        'event_date' => 'date',
        'end_date' => 'date',
        'notify_all' => 'boolean',
    ];

    // Relationships
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
