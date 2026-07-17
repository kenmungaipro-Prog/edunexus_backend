<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\{School,  Subject};

class SubjectSeeder extends Seeder
{
    public function run(): void
    {
        $school = School::first();

        $subjects = [
            ['name' => 'Mathematics',    'code' => 'MATH', 'type' => 'core'],
            ['name' => 'Physics',        'code' => 'PHY',  'type' => 'core'],
            ['name' => 'Chemistry',      'code' => 'CHEM', 'type' => 'core'],
            ['name' => 'Biology',        'code' => 'BIO',  'type' => 'core'],
            ['name' => 'English',        'code' => 'ENG',  'type' => 'core'],
            ['name' => 'Hindi',          'code' => 'HIN',  'type' => 'core'],
            ['name' => 'Social Studies', 'code' => 'SST',  'type' => 'core'],
            ['name' => 'Computer Science','code' => 'CS',  'type' => 'elective'],
            ['name' => 'Physical Education','code' => 'PE','type' => 'activity'],
            ['name' => 'Art & Craft',    'code' => 'ART',  'type' => 'activity'],
        ];

        foreach ($subjects as $s) {
            Subject::updateOrCreate(
                ['school_id' => $school->id, 'code' => $s['code']],
                array_merge(['school_id' => $school->id], $s)
            );
        }
    }
}

