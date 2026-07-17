<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Teacher;
use App\Models\Subject;

class TeacherSubjectSeeder extends Seeder
{
    public function run(): void
    {
        $subjects = Subject::all();
        $teachers = Teacher::all();

        // Ensure we have data to link
        if ($subjects->isEmpty() || $teachers->isEmpty()) {
            return;
        }

        foreach ($teachers as $teacher) {
            // Assign 1 to 3 random subjects to each teacher
            $randomSubjectIds = $subjects->random(rand(1, 3))->pluck('id');
            
            // sync() is safer than attach() as it prevents duplicates 
            // if you run the seeder multiple times
            $teacher->subjects()->sync($randomSubjectIds);
        }
    }
}