<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Exam extends Model
{
    use HasFactory;

    protected $table = 'exams';

    protected $fillable = [
        'class_id',
        'subject_id',
        'session_id',
        'created_by',
        'invigilator_id',
        'title',
        'exam_date',
        'start_time',
        'end_time',
        'total_marks',
        'passing_marks',
        'room',
        'instructions',
        'status',
    ];

    protected $casts = [
        'exam_date' => 'date',
        'total_marks' => 'integer',
        'passing_marks' => 'integer',
    ];

    // Relationships
    public function classRoom(): BelongsTo
    {
        return $this->belongsTo(ClassRoom::class, 'class_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'session_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function invigilator(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'invigilator_id');
    }

    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class);
    }
}
