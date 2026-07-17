<?php

namespace Database\Seeders;

use App\Models\Exam;
use Illuminate\Database\Seeder;

class ExamSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create 20 random exams
        Exam::factory()->count(20)->create();

        // Optional: Create specific exams for testing
        Exam::updateOrCreate(
            ['class_id' => 1, 'subject_id' => 1, 'session_id' => 1, 'title' => 'Biology Final Exam'],
            [
                'created_by' => 1,
                'exam_date' => now()->addDays(5)->format('Y-m-d'),
                'start_time' => '09:00:00',
                'end_time' => '12:00:00',
                'total_marks' => 100,
                'passing_marks' => 35,
                'room' => 'Lab A1',
                'status' => 'scheduled',
            ]
        );
    }
}