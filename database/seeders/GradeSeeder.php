<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Grade;
use App\Models\Student;
use App\Models\User;
use App\Models\Exam;

class GradeSeeder extends Seeder
{
    public function run(): void
    {
        $student = Student::first();
        $exam = Exam::first();
        $admin = User::where('role', 'admin')->first();

        if (!$student || !$exam || !$admin) {
            return; // Skip if dependencies aren't met
        }

        Grade::updateOrCreate(
            ['exam_id' => $exam->id, 'student_id' => $student->id],
            [
                'class_id'       => $student->class_id, // Scopes the grade to the student's class
                'entered_by'     => $admin->id,
                'marks_obtained' => 85.00,
                'total_marks'    => 100,
                'percentage'     => 85.00,
                'letter_grade'   => 'A',
                'status'         => 'pass',
                'remarks'        => 'Excellent performance',
            ]
        );
    }
}
