<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\School;
use App\Models\AcademicSession;

class AcademicSessionSeeder extends Seeder
{
    public function run(): void
    {
        $school = School::first();

        AcademicSession::updateOrCreate(
            ['school_id' => $school->id, 'name' => '2023-24'],
            ['start_date' => '2023-04-01', 'end_date' => '2024-03-31', 'is_current' => false]
        );

        AcademicSession::updateOrCreate(
            ['school_id' => $school->id, 'name' => '2024-25'],
            ['start_date' => '2024-04-01', 'end_date' => '2025-03-31', 'is_current' => true]
        );
    }
}

