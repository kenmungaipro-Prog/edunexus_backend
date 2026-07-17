<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\ClassRoom;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected AcademicSession $session;
    protected ClassRoom $classRoom;
    protected Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create([
            'name' => 'RBAC School',
            'address' => '1 RBAC Blvd',
            'phone' => '254700100100',
            'email' => 'rbac@test.example',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->session = AcademicSession::create([
            'school_id' => $this->school->id,
            'name' => '2026',
            'is_current' => true,
            'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->classRoom = ClassRoom::create([
            'school_id' => $this->school->id,
            'session_id' => $this->session->id,
            'name' => 'RBAC Grade',
            'grade' => 1,
            'section' => 'A',
            'capacity' => 30,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->student = Student::create([
            'school_id' => $this->school->id,
            'session_id' => $this->session->id,
            'class_id' => $this->classRoom->id,
            'admission_no' => 'RBAC001',
            'roll_number' => '10',
            'first_name' => 'RBAC',
            'last_name' => 'Student',
            'date_of_birth' => now()->subYears(12)->toDateString(),
            'gender' => 'female',
            'address' => '123 Role St',
            'status' => 'active',
            'admission_date' => now()->subYear()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_admin_can_access_finance_payments_index()
    {
        $admin = User::create([
            'school_id' => $this->school->id,
            'name' => 'Admin User',
            'email' => 'rbac-admin@test.example',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->getJson('/api/v1/finance/payments')
            ->assertStatus(200);
    }

    public function test_accountant_can_access_finance_payments_index()
    {
        $accountant = User::create([
            'school_id' => $this->school->id,
            'name' => 'Accountant User',
            'email' => 'rbac-accountant@test.example',
            'password' => bcrypt('password'),
            'role' => 'accountant',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($accountant)
            ->getJson('/api/v1/finance/payments')
            ->assertStatus(200);
    }

    public function test_teacher_is_denied_access_to_finance_payments_index()
    {
        $teacher = User::create([
            'school_id' => $this->school->id,
            'name' => 'Teacher User',
            'email' => 'rbac-teacher@test.example',
            'password' => bcrypt('password'),
            'role' => 'teacher',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($teacher)
            ->getJson('/api/v1/finance/payments')
            ->assertStatus(403);
    }

    public function test_parent_is_denied_access_to_finance_payments_index()
    {
        $parent = User::create([
            'school_id' => $this->school->id,
            'name' => 'Parent User',
            'email' => 'rbac-parent@test.example',
            'password' => bcrypt('password'),
            'role' => 'parent',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($parent)
            ->getJson('/api/v1/finance/payments')
            ->assertStatus(403);
    }

    public function test_parent_can_access_their_child_finance_statement_route()
    {
        $parent = User::create([
            'school_id' => $this->school->id,
            'name' => 'Parent User',
            'email' => 'rbac-parent@test.example',
            'password' => bcrypt('password'),
            'role' => 'parent',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->student->update(['parent_id' => $parent->id]);

        $this->actingAs($parent)
            ->getJson("/api/v1/students/{$this->student->id}/statement")
            ->assertStatus(200);
    }

    public function test_student_without_relationship_is_denied_access_to_finance_statement_route()
    {
        $studentUser = User::create([
            'school_id' => $this->school->id,
            'name' => 'Student User',
            'email' => 'rbac-student@test.example',
            'password' => bcrypt('password'),
            'role' => 'student',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($studentUser)
            ->getJson("/api/v1/students/{$this->student->id}/statement")
            ->assertStatus(403);
    }
}
