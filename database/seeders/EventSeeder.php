<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\{School, User,  Event};

class EventSeeder extends Seeder
{
    public function run(): void
    {
        $school = School::first();
        $admin  = User::where('role', 'admin')->first();

        $events = [
            ['title' => 'Annual Sports Day',       'event_date' => '2025-04-20', 'type' => 'event',       'venue' => 'School Grounds'],
            ['title' => 'Parent-Teacher Meeting',  'event_date' => '2025-04-25', 'type' => 'meeting',     'venue' => 'School Auditorium'],
            ['title' => 'Science Exhibition',      'event_date' => '2025-05-02', 'type' => 'event',       'venue' => 'Hall A'],
            ['title' => 'Final Exams Begin',       'event_date' => '2025-05-10', 'type' => 'exam',        'venue' => 'All Classrooms'],
            ['title' => 'Inter-School Quiz',       'event_date' => '2025-05-15', 'type' => 'competition', 'venue' => 'Auditorium'],
            ['title' => 'Summer Break Begins',     'event_date' => '2025-05-25', 'type' => 'holiday',     'venue' => null],
        ];

        foreach ($events as $e) {
            Event::updateOrCreate(
                ['school_id' => $school->id, 'title' => $e['title'], 'event_date' => $e['event_date']],
                array_merge(['created_by' => $admin->id], $e)
            );
        }
    }
}

