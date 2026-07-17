<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Teacher extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'school_id',
        'employee_id',
        'phone',
        'department',
        'qualification',
        'experience_yrs',
        'join_date',
        'salary',
        'status',
    ];

    protected $casts = [
        'join_date' => 'date',
        'salary' => 'decimal:2',
        'experience_yrs' => 'integer',
    ];

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'teacher_subjects');
    }

    public function teacherSubjects(): HasMany
    {
        return $this->hasMany(TeacherSubject::class);
    }

    public function classRooms(): HasMany
    {
        return $this->hasMany(ClassRoom::class, 'class_teacher_id');
    }

    public function examsAsInvigilator(): HasMany
    {
        return $this->hasMany(Exam::class, 'invigilator_id');
    }

    public function timetableSlots(): HasMany
    {
        return $this->hasMany(TimetableSlot::class);
    }

    
}
