<?php

namespace Database\Seeders;

use App\Models\ParentProfile;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class ParentSeeder extends Seeder
{
    public function run(): void
    {
        $school = School::first();
        if (!$school) {
            return; // Ensure school exists before seeding parents.
        }

        $students = Student::take(3)->get();

        $parents = [
            [
                'name' => 'Priya Kumar',
                'email' => 'parent@greenwood.edu.in',
                'relationship' => 'Mother',
                'phone' => '0712345678',
                'occupation' => 'Teacher',
                'address' => '123 Maple Street, Greenwood City',
                'notes' => 'Primary guardian for Arav Sharma',
                'student_index' => 0,
            ],
            [
                'name' => 'Rajesh Menon',
                'email' => 'parent2@greenwood.edu.in',
                'relationship' => 'Father',
                'phone' => '0723456789',
                'occupation' => 'Engineer',
                'address' => '34 Cedar Avenue, Greenwood City',
                'notes' => 'Primary guardian for Diya Patel',
                'student_index' => 1,
            ],
            [
                'name' => 'Sunita Das',
                'email' => 'parent3@greenwood.edu.in',
                'relationship' => 'Mother',
                'phone' => '0734567890',
                'occupation' => 'Accountant',
                'address' => '56 Oak Road, Greenwood City',
                'notes' => 'Primary guardian for Rohan Das',
                'student_index' => 2,
            ],
        ];

        foreach ($parents as $data) {
            $user = User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'school_id' => $school->id,
                    'name' => $data['name'],
                    'password' => Hash::make('password'),
                    'role' => 'parent',
                    'status' => 'active',
                ]
            );

            $profile = ParentProfile::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'school_id' => $school->id,
                    'relationship' => $data['relationship'],
                    'phone' => $data['phone'],
                    'address' => $data['address'],
                    'occupation' => $data['occupation'],
                    'notes' => $data['notes'],
                ]
            );

            if (isset($students[$data['student_index']])) {
                $students[$data['student_index']]->update(['parent_id' => $user->id]);
            }
        }
    }
}
