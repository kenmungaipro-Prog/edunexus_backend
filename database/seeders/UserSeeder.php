<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\{School, User};
use Illuminate\Support\Facades\Hash; 

// ============================================================
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $school = School::first();

        $users = [
            ['name' => 'Super Admin',        'email' => 'superadmin@edunexus.com', 'role' => 'superadmin', 'school_id' => null],
            ['name' => 'School Admin',       'email' => 'admin@greenwood.edu.in',  'role' => 'admin'],
            ['name' => 'Dr. Sunita Rao',     'email' => 'sunita.rao@greenwood.edu.in', 'role' => 'teacher'],
            ['name' => 'Mr. Arjun Pillai',   'email' => 'arjun.pillai@greenwood.edu.in', 'role' => 'teacher'],
            ['name' => 'Ms. Kavya Menon',    'email' => 'kavya.menon@greenwood.edu.in', 'role' => 'teacher'],
            ['name' => 'Priya Accountant',   'email' => 'accounts@greenwood.edu.in', 'role' => 'accountant'],
            ['name' => 'Rajan Librarian',    'email' => 'library@greenwood.edu.in', 'role' => 'librarian'],
            ['name' => 'Arjun Kumar',        'email' => 'student@greenwood.edu.in', 'role' => 'student'],
            ['name' => 'Mrs. Priya Kumar',   'email' => 'parent@greenwood.edu.in', 'role' => 'parent'],
            ['name' => 'Frank Castle',       'email' => 'frank.castle@greenwood.edu.in', 'role' => 'driver'],
            ['name' => 'Sarah Connor',       'email' => 'sarah.connor@greenwood.edu.in', 'role' => 'driver'],
            ['name' => 'Joel Miller',        'email' => 'joel.miller@greenwood.edu.in', 'role' => 'driver'],
            ['name' => 'Ellen Ripley',       'email' => 'ellen.ripley@greenwood.edu.in', 'role' => 'driver'],
        ];

        foreach ($users as $userData) {
            User::updateOrCreate(
                ['email' => $userData['email']],
                [
                    'school_id' => $userData['school_id'] ?? $school->id,
                    'name'      => $userData['name'],
                    'password'  => Hash::make('password'),
                    'role'      => $userData['role'],
                    'status'    => 'active',
                ]
            );
        }
    }
}

