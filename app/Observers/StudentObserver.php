<?php

// ============================================================
// app/Observers/StudentObserver.php
// ============================================================
namespace App\Observers;

use App\Models\Student;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class StudentObserver
{
    public function created(Student $student): void
    {
        Cache::forget("dashboard.stats.{$student->school_id}");
        Log::info("Student created: {$student->full_name} ({$student->admission_no})");
    }

    public function updated(Student $student): void
    {
        Cache::forget("dashboard.stats.{$student->school_id}");
    }

    public function deleted(Student $student): void
    {
        Cache::forget("dashboard.stats.{$student->school_id}");
        Log::info("Student removed: {$student->full_name} ({$student->admission_no})");
    }

    /**
     * Register in AppServiceProvider::boot():
     * Student::observe(StudentObserver::class);
     */
}
