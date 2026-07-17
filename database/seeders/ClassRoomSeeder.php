<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ClassRoom;
use App\Models\AcademicSession;

class ClassRoomSeeder extends Seeder
{
    public function run(): void
    {
        $session = AcademicSession::first();
        
        if (!$session) {
            throw new \Exception("No Academic Session found.");
        }

        $classes = [
            // Added 'grade' to each array
            ['name' => 'Grade 10-A', 'section' => 'A', 'grade' => 10, 'school_id' => 1, 'session_id' => $session->id],
            ['name' => 'Grade 10-B', 'section' => 'B', 'grade' => 10, 'school_id' => 1, 'session_id' => $session->id],
            ['name' => 'Grade 11-Science', 'section' => 'S1', 'grade' => 11, 'school_id' => 1, 'session_id' => $session->id],
            ['name' => 'Grade 12-Commerce', 'section' => 'C1', 'grade' => 12, 'school_id' => 1, 'session_id' => $session->id],
        ];

        foreach ($classes as $class) {
            ClassRoom::updateOrCreate(
                [
                    'school_id'  => $class['school_id'],
                    'session_id' => $class['session_id'],
                    'grade'      => $class['grade'],
                    'section'    => $class['section'],
                ],
                $class
            );
        }
    }
}
