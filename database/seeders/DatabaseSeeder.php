<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\{School, User, Teacher, Student, ClassRoom, Subject, AcademicSession, FeeType, Book, Event};

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CleanupDuplicateSchoolsSeeder::class,
            SchoolSeeder::class,
            CleanupDuplicateUsersSeeder::class,
            UserSeeder::class,
            AcademicSessionSeeder::class,
            SubjectSeeder::class,
            CleanupDuplicateClassRoomsSeeder::class,
            ClassRoomSeeder::class,
            TeacherSeeder::class,
            StudentSeeder::class,
            ParentSeeder::class,
            FeeTypeSeeder::class,
            FeeSeeder::class,
            BookSeeder::class,
            EventSeeder::class,
            TimetableSeeder::class,
            ChartOfAccountSeeder::class,
            PaymentGatewayConfigSeeder::class,
        ]);
    }
}
