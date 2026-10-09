<?php

namespace Database\Seeders;

use App\Models\AcademicSession;
use App\Models\ClassRoom;
use App\Models\ParentProfile;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ParentLinkDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Parent-link demo data can only be seeded in a local or testing environment.');
        }

        if (! Schema::hasColumn('students', 'secondary_parent_id')) {
            throw new RuntimeException('Run pending database migrations before seeding parent-link demo data.');
        }

        DB::transaction(function () {
            $school = School::query()->first();
            if (! $school) {
                throw new RuntimeException('Create a local school before seeding parent-link demo data.');
            }

            $session = AcademicSession::query()
                ->where('school_id', $school->id)
                ->orderByDesc('is_current')
                ->first();

            if (! $session) {
                $session = AcademicSession::create([
                    'school_id' => $school->id,
                    'name' => 'Local Demo Session',
                    'start_date' => now()->startOfYear()->toDateString(),
                    'end_date' => now()->endOfYear()->toDateString(),
                    'is_current' => true,
                ]);
            }

            $classRoom = ClassRoom::query()
                ->where('school_id', $school->id)
                ->where('session_id', $session->id)
                ->first();

            if (! $classRoom) {
                $classRoom = ClassRoom::create([
                    'school_id' => $school->id,
                    'session_id' => $session->id,
                    'name' => 'Local Demo Class',
                    'grade' => 12,
                    'section' => 'LD',
                    'capacity' => 10,
                ]);
            }

            $primaryParent = $this->createParent(
                $school->id,
                'local-demo-parent-one@example.test',
                'Local Demo Parent One',
                'Mother'
            );

            $secondaryParent = $this->createParent(
                $school->id,
                'local-demo-parent-two@example.test',
                'Local Demo Parent Two',
                'Father'
            );

            Student::query()->updateOrCreate(
                ['admission_no' => 'LOCAL-DEMO-PARENTS-001'],
                [
                    'school_id' => $school->id,
                    'session_id' => $session->id,
                    'class_id' => $classRoom->id,
                    'parent_id' => $primaryParent->id,
                    'secondary_parent_id' => $secondaryParent->id,
                    'first_name' => 'Local Demo',
                    'last_name' => 'Student',
                    'roll_number' => 'LOCAL-DEMO-001',
                    'date_of_birth' => '2015-05-15',
                    'gender' => 'other',
                    'address' => 'Synthetic local test record',
                    'status' => 'active',
                    'admission_date' => now()->toDateString(),
                ]
            );
        });
    }

    private function createParent(int $schoolId, string $email, string $name, string $relationship): User
    {
        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'school_id' => $schoolId,
                'name' => $name,
                'password' => Hash::make('LocalDemo@123'),
                'role' => 'parent',
                'status' => 'active',
            ]
        );

        ParentProfile::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'school_id' => $schoolId,
                'relationship' => $relationship,
                'phone' => null,
            ]
        );

        return $user;
    }
}
