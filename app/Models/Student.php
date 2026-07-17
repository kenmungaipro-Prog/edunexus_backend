<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

class Student extends Model
{
    use HasFactory, SoftDeletes;

    use SoftDeletes;

    protected $appends = [
        'full_name',
    ];

    protected $fillable = [
        'school_id',
        'session_id',
        'class_id',
        'parent_id',
        'secondary_parent_id',
        'emergency_contact_parent_id',
        'admission_no',
        'roll_number',
        'first_name',
        'last_name',
        'date_of_birth',
        'gender',
        'blood_group',
        'address',
        'profile_photo',
        'status',
        'admission_date',
        'religion',
        'category',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'admission_date' => 'date',
    ];

    // Relationships
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'session_id');
    }

    public function classRoom(): BelongsTo
    {
        return $this->belongsTo(ClassRoom::class, 'class_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'parent_id');
    }

    public function secondaryParent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'secondary_parent_id');
    }

    public function emergencyContactParent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'emergency_contact_parent_id');
    }

    public function parentProfile(): HasOneThrough
    {
        return $this->hasOneThrough(ParentProfile::class, User::class, 'id', 'user_id', 'parent_id', 'id');
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class);
    }

    public function fees(): HasMany
    {
        return $this->hasMany(Fee::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function studentFinanceBalance(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(StudentFinanceBalance::class);
    }

    public function transportAssignments(): HasMany
    {
        return $this->hasMany(TransportAssignment::class);
    }

    public function bookIssues(): HasMany
    {
        return $this->hasMany(BookIssue::class, 'member_id');
    }

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }
}
