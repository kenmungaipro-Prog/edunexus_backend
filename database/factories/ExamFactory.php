<?php

namespace Database\Factories;

use App\Models\AcademicSession;
use App\Models\ClassRoom;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExamFactory extends Factory
{
    public function definition(): array
{
    $startDate = $this->faker->dateTimeBetween('-1 month', '+2 months');
    $startTime = $this->faker->dateTimeBetween('08:00:00', '14:00:00');
    
    $endTime = clone $startTime;
    $endTime->modify('+2 hours');

    return [
        'class_id'   => ClassRoom::inRandomOrder()->first()?->id ?? 1,
        'subject_id' => Subject::inRandomOrder()->first()?->id ?? 1,
        
        // Uses is_current from your academic_sessions table
        'session_id' => AcademicSession::where('is_current', true)->first()?->id 
                        ?? AcademicSession::first()?->id 
                        ?? 1,
                        
        // Updated: Look for a user with the 'admin' or 'teacher' role string
        'created_by' => User::where('role', 'admin')->first()?->id 
                        ?? User::first()?->id 
                        ?? 1,
        
        'invigilator_id' => Teacher::inRandomOrder()->first()?->id ?? null,
        
        'title'          => $this->faker->randomElement(['Mid-Term', 'Final', 'Unit Quiz']) . ' Examination',
        'exam_date'      => $startDate->format('Y-m-d'),
        'start_time'     => $startTime->format('H:i:s'),
        'end_time'       => $endTime->format('H:i:s'),
        'total_marks'    => 100,
        'passing_marks'  => 40,
        'room'           => 'Hall ' . $this->faker->bothify('#??'),
        'instructions'   => 'Please bring your ID card. No calculators allowed.',
        'status'         => $this->faker->randomElement(['scheduled', 'ongoing', 'completed', 'cancelled']),
    ];
}
}