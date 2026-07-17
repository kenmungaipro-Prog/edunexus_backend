<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'name',
        'start_date',
        'end_date',
        'is_current',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_current' => 'boolean',
    ];

    // Relationships
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function classRooms(): HasMany
    {
        return $this->hasMany(ClassRoom::class, 'session_id');
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class, 'session_id');
    }

    public function exams(): HasMany
    {
        return $this->hasMany(Exam::class, 'session_id');
    }

    public function fees(): HasMany
    {
        return $this->hasMany(Fee::class, 'session_id');
    }

    public function feeStructures(): HasMany
    {
        return $this->hasMany(FeeStructure::class, 'session_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'session_id');
    }
}
