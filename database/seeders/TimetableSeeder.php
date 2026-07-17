<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ClassRoom;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TimetableSlot;
use Carbon\Carbon;

class TimetableSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Clear existing slots to start fresh
        TimetableSlot::truncate();

        $classes = ClassRoom::all();
        $days = range(1, 6);   // Mon to Sat
        $periods = range(1, 8); // 8 periods per day

        foreach ($classes as $class) {
            // Get subjects assigned to this class. 
            // If you don't have a pivot table yet, we'll just grab random subjects.
            $subjects = Subject::all();
            
            if ($subjects->isEmpty()) {
                $this->command->warn("No subjects found. Skipping class: {$class->name}");
                continue;
            }

            foreach ($days as $day) {
                foreach ($periods as $period) {
                    // Pick a random subject
                    $subject = $subjects->random();
                    
                    // Pick a teacher who teaches this subject
                    // Assumes a relationship: $subject->teachers
                    $teacher = $subject->teachers()->first() ?? Teacher::inRandomOrder()->first();

                    if (!$teacher) continue;

                    // Calculate times (e.g., starting at 8:00 AM, 1 hour per period)
                    $startHour = 7 + $period; // Period 1 starts at 8:00
                    $startTime = Carbon::createFromTime($startHour, 0);
                    $endTime = (clone $startTime)->addHour();

                    // Check if teacher is already booked for this specific time slot
                    $isBooked = TimetableSlot::where([
                        'teacher_id'   => $teacher->id,
                        'day_of_week'   => $day,
                        'period_number' => $period,
                    ])->exists();

                    if ($isBooked) {
                        // Try to find another teacher for the same subject if possible
                        continue; 
                    }

                    TimetableSlot::updateOrCreate(
                        [
                            'class_id'      => $class->id,
                            'day_of_week'   => $day,
                            'period_number' => $period,
                        ],
                        [
                            'subject_id'    => $subject->id,
                            'teacher_id'    => $teacher->id,
                            'start_time'    => $startTime->format('H:i'),
                            'end_time'      => $endTime->format('H:i'),
                            'room'          => 'Room ' . rand(101, 505),
                        ]
                    );
                }
            }
            $this->command->info("Populated timetable for: {$class->name}");
        }
    }
}