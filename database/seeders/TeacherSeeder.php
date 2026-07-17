<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Teacher;
use App\Models\School;
use App\Models\Subject;
use Illuminate\Support\Facades\Hash;

class TeacherSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Get a School ID (Assuming at least one school exists)
        $schoolId = School::first()?->id ?? 1;

        $teachersData = [
            [
                'name' => 'Dr. Sunita Rao',
                'email' => 'sunita.rao@school.com',
                'employee_id' => 'T-001',
                'department' => 'Science',
                'experience' => 12,
                'status' => 'active',
                'subjects' => ['Physics', 'Chemistry']
            ],
            [
                'name' => 'Mr. Arjun Pillai',
                'email' => 'arjun.pillai@school.com',
                'employee_id' => 'T-002',
                'department' => 'Mathematics',
                'experience' => 8,
                'status' => 'active',
                'subjects' => ['Maths', 'Statistics']
            ],
            [
                'name' => 'Ms. Kavya Menon',
                'email' => 'kavya.menon@school.com',
                'employee_id' => 'T-003',
                'department' => 'English',
                'experience' => 6,
                'status' => 'active',
                'subjects' => ['English Lit', 'Grammar']
            ],
            [
                'name' => 'Mr. Rajan Iyer',
                'email' => 'rajan.iyer@school.com',
                'employee_id' => 'T-004',
                'department' => 'Social Studies',
                'experience' => 15,
                'status' => 'on_leave', // Match your enum 'on_leave'
                'subjects' => ['History', 'Geography']
            ],
            [
                'name' => 'Ms. Divya Shah',
                'email' => 'divya.shah@school.com',
                'employee_id' => 'T-005',
                'department' => 'Computer',
                'experience' => 4,
                'status' => 'active',
                'subjects' => ['CS', 'Python']
            ],
        ];

        foreach ($teachersData as $data) {
            // Create or update the User record
            $user = User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => Hash::make('password'), // Default password
                    'role' => 'teacher',
                ]
            );

            // Create or update the Teacher record
            $teacher = Teacher::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'school_id' => $schoolId,
                    'employee_id' => $data['employee_id'],
                    'department' => $data['department'],
                    'experience_yrs' => $data['experience'],
                    'status' => $data['status'],
                    'join_date' => now()->subYears(2), // Generic join date
                ]
            );

            // Optional: If you want to link the subjects right now
            // Find existing subjects by name and attach them to the teacher
            $subjectIds = Subject::whereIn('name', $data['subjects'])->pluck('id');
            $teacher->subjects()->attach($subjectIds);
        }
    }
}