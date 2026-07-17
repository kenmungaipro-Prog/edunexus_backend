<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Student;
use App\Models\ClassRoom;
use App\Models\AcademicSession;

class StudentSeeder extends Seeder
{
    public function run(): void
    {
        $session = AcademicSession::first();
        $class = ClassRoom::first();
        
        if (!$session || !$class) {
            throw new \Exception("Ensure AcademicSession and ClassRoom seeders run before StudentSeeder.");
        }

        $students = [
            ['first_name' => 'Arav', 'last_name' => 'Sharma', 'gender' => 'male'],
            ['first_name' => 'Diya', 'last_name' => 'Patel',  'gender' => 'female'],
            ['first_name' => 'Rohan', 'last_name' => 'Das',    'gender' => 'male'],
        ];

        foreach ($students as $index => $s) {
            $admissionNo = 'ADM-' . str_pad($index + 1, 5, '0', STR_PAD_LEFT);
            Student::updateOrCreate(
                ['admission_no' => $admissionNo],
                [
                    'school_id'        => 1,
                    'session_id'       => $session->id,
                    'class_id'         => $class->id,
                    'parent_id'        => null, // Nullable per migration
                    'admission_no'     => $admissionNo,
                    'roll_number'      => (string)($index + 101), // string type in migration
                    'first_name'       => $s['first_name'],
                    'last_name'        => $s['last_name'],
                    'date_of_birth'    => '2010-05-15',
                    'gender'           => $s['gender'],
                    'blood_group'      => 'O+',
                    'address'          => '123 School Lane, City Center',
                    'status'           => 'active',
                    'admission_date'   => now(),
                    'religion'         => 'Other',
                    'category'         => 'General',
                ]
            );
        }
    }
}
