<?php

// ============================================================
// tests/Feature/AuthTest.php
// Run: php artisan test --filter AuthTest
// ============================================================

use App\Models\User;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->school = School::factory()->create();
    $this->admin  = User::factory()->create([
        'school_id' => $this->school->id,
        'role'      => 'admin',
        'email'     => 'admin@test.com',
        'password'  => bcrypt('password'),
        'status'    => 'active',
    ]);
});

test('admin can login with correct credentials', function () {
    $response = $this->postJson('/api/v1/auth/login', [
        'email'    => 'admin@test.com',
        'password' => 'password',
    ]);

    $response->assertStatus(200)
             ->assertJsonStructure([
                 'success',
                 'data' => ['user' => ['id', 'name', 'email', 'role'], 'token'],
             ]);

    expect($response->json('success'))->toBeTrue();
    expect($response->json('data.user.role'))->toBe('admin');
});

test('login fails with wrong password', function () {
    $this->postJson('/api/v1/auth/login', [
        'email'    => 'admin@test.com',
        'password' => 'wrong-password',
    ])->assertStatus(422);
});

test('login fails for inactive user', function () {
    $this->admin->update(['status' => 'inactive']);

    $this->postJson('/api/v1/auth/login', [
        'email'    => 'admin@test.com',
        'password' => 'password',
    ])->assertStatus(403);
});

test('authenticated user can get their profile', function () {
    $response = $this->actingAs($this->admin)
        ->getJson('/api/v1/auth/me');

    $response->assertStatus(200)
             ->assertJsonPath('data.id',    $this->admin->id)
             ->assertJsonPath('data.email', $this->admin->email);
});

test('unauthenticated request returns 401', function () {
    $this->getJson('/api/v1/auth/me')->assertStatus(401);
});

test('user can logout', function () {
    $this->actingAs($this->admin)
        ->postJson('/api/v1/auth/logout')
        ->assertStatus(200)
        ->assertJsonPath('success', true);
});

test('user can change password', function () {
    $this->actingAs($this->admin)
        ->postJson('/api/v1/auth/change-password', [
            'current_password'      => 'password',
            'password'              => 'NewPassword@123',
            'password_confirmation' => 'NewPassword@123',
        ])
        ->assertStatus(200);
});

test('teacher cannot access admin-only endpoints', function () {
    $teacher = User::factory()->create([
        'school_id' => $this->school->id,
        'role'      => 'teacher',
        'status'    => 'active',
    ]);

    $this->actingAs($teacher)
        ->deleteJson('/api/v1/teachers/1')
        ->assertStatus(403);
});

// ============================================================
// tests/Feature/StudentTest.php
// ============================================================

use App\Models\{Student, ClassRoom, AcademicSession};

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->school  = School::factory()->create();
    $this->session = AcademicSession::factory()->create([
        'school_id'  => $this->school->id,
        'is_current' => true,
    ]);
    $this->class = ClassRoom::factory()->create([
        'school_id'  => $this->school->id,
        'session_id' => $this->session->id,
    ]);
    $this->admin = User::factory()->create([
        'school_id' => $this->school->id,
        'role'      => 'admin',
        'status'    => 'active',
    ]);
});

test('admin can list students', function () {
    Student::factory(5)->create([
        'school_id'  => $this->school->id,
        'class_id'   => $this->class->id,
        'session_id' => $this->session->id,
    ]);

    $response = $this->actingAs($this->admin)
        ->getJson('/api/v1/students');

    $response->assertStatus(200)
             ->assertJsonStructure([
                 'success',
                 'data' => ['data', 'meta'],
             ]);

    expect(count($response->json('data.data')))->toBe(Student::where('school_id', $this->school->id)->count());
});

test('admin can create a student', function () {
    $payload = [
        'first_name'    => 'Aarav',
        'last_name'     => 'Sharma',
        'date_of_birth' => '2008-05-15',
        'gender'        => 'male',
        'class_id'      => $this->class->id,
        'parent_name'   => 'Raj Sharma',
        'parent_email'  => 'raj@example.com',
        'parent_phone'  => '+91 99999 88888',
    ];

    $response = $this->actingAs($this->admin)
        ->postJson('/api/v1/students', $payload);

    $response->assertStatus(201)
             ->assertJsonPath('data.first_name', 'Aarav')
             ->assertJsonPath('data.last_name',  'Sharma');

    $this->assertDatabaseHas('students', [
        'first_name' => 'Aarav',
        'last_name'  => 'Sharma',
        'school_id'  => $this->school->id,
    ]);

    // Parent user should have been created
    $this->assertDatabaseHas('users', [
        'email' => 'raj@example.com',
        'role'  => 'parent',
    ]);

    // And the parent profile should exist with the provided phone number.
    $this->assertDatabaseHas('parent_profiles', [
        'phone' => '+91 99999 88888',
    ]);
});

test('student creation requires first name', function () {
    $this->actingAs($this->admin)
        ->postJson('/api/v1/students', [
            'last_name'    => 'Sharma',
            'date_of_birth'=> '2008-05-15',
            'gender'       => 'male',
            'class_id'     => $this->class->id,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['first_name']);
});

test('admin can update a student', function () {
    $student = Student::factory()->create([
        'school_id'  => $this->school->id,
        'class_id'   => $this->class->id,
        'session_id' => $this->session->id,
    ]);

    $this->actingAs($this->admin)
        ->putJson("/api/v1/students/{$student->id}", [
            'first_name' => 'Updated',
            'last_name'  => $student->last_name,
            'date_of_birth' => $student->date_of_birth->format('Y-m-d'),
            'gender'     => $student->gender,
            'class_id'   => $this->class->id,
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.first_name', 'Updated');
});

test('admin can soft-delete a student', function () {
    $student = Student::factory()->create([
        'school_id'  => $this->school->id,
        'class_id'   => $this->class->id,
        'session_id' => $this->session->id,
    ]);

    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/students/{$student->id}")
        ->assertStatus(200);

    $this->assertSoftDeleted('students', ['id' => $student->id]);
});

test('students can be searched by name', function () {
    Student::factory()->create([
        'first_name' => 'Aarav', 'last_name' => 'Sharma',
        'school_id' => $this->school->id, 'class_id' => $this->class->id, 'session_id' => $this->session->id,
    ]);
    Student::factory()->create([
        'first_name' => 'Priya', 'last_name' => 'Nair',
        'school_id' => $this->school->id, 'class_id' => $this->class->id, 'session_id' => $this->session->id,
    ]);

    $response = $this->actingAs($this->admin)
        ->getJson('/api/v1/students?search=Aarav');

    expect(count($response->json('data.data')))->toBe(1);
    expect($response->json('data.data.0.first_name'))->toBe('Aarav');
});

// ============================================================
// tests/Feature/FeeTest.php
// ============================================================

use App\Models\{Fee, FeeType};

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->school     = School::factory()->create();
    $this->session    = AcademicSession::factory()->create(['school_id' => $this->school->id, 'is_current' => true]);
    $this->class      = ClassRoom::factory()->create(['school_id' => $this->school->id, 'session_id' => $this->session->id]);
    $this->student    = Student::factory()->create(['school_id' => $this->school->id, 'class_id' => $this->class->id, 'session_id' => $this->session->id]);
    $this->feeType    = FeeType::factory()->create(['school_id' => $this->school->id, 'amount' => 12500]);
    $this->accountant = User::factory()->create(['school_id' => $this->school->id, 'role' => 'accountant', 'status' => 'active']);
    $this->admin      = User::factory()->create(['school_id' => $this->school->id, 'role' => 'admin', 'status' => 'active']);
});

test('accountant can collect a fee', function () {
    $response = $this->actingAs($this->accountant)
        ->postJson('/api/v1/fees/collect', [
            'student_id'     => $this->student->id,
            'fee_type_id'    => $this->feeType->id,
            'amount'         => 12500,
            'payment_method' => 'cash',
        ]);

    $response->assertStatus(201)
             ->assertJsonPath('success', true)
             ->assertJsonStructure(['data' => ['receipt_no', 'amount', 'status']]);

    $this->assertDatabaseHas('fees', [
        'student_id'     => $this->student->id,
        'amount'         => 12500,
        'status'         => 'paid',
        'payment_method' => 'cash',
    ]);
});

test('fee requires transaction id for upi payment', function () {
    $this->actingAs($this->accountant)
        ->postJson('/api/v1/fees/collect', [
            'student_id'     => $this->student->id,
            'fee_type_id'    => $this->feeType->id,
            'amount'         => 12500,
            'payment_method' => 'upi',
            // missing transaction_id
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['transaction_id']);
});

test('fee summary returns correct totals', function () {
    Fee::factory(3)->create([
        'student_id'   => $this->student->id,
        'fee_type_id'  => $this->feeType->id,
        'session_id'   => $this->session->id,
        'collected_by' => $this->accountant->id,
        'amount'       => 5000,
        'status'       => 'paid',
    ]);

    $response = $this->actingAs($this->admin)
        ->getJson('/api/v1/fees/summary');

    $response->assertStatus(200)
             ->assertJsonPath('data.total_collected', 15000.0);
});

test('teacher cannot access fee endpoints', function () {
    $teacher = User::factory()->create(['school_id' => $this->school->id, 'role' => 'teacher', 'status' => 'active']);

    $this->actingAs($teacher)
        ->postJson('/api/v1/fees/collect', [])
        ->assertStatus(403);
});

test('receipt generates pdf for paid fee', function () {
    $fee = Fee::factory()->create([
        'student_id'   => $this->student->id,
        'fee_type_id'  => $this->feeType->id,
        'session_id'   => $this->session->id,
        'collected_by' => $this->accountant->id,
        'status'       => 'paid',
    ]);

    $this->actingAs($this->admin)
        ->get("/api/v1/fees/{$fee->id}/receipt")
        ->assertStatus(200)
        ->assertHeader('Content-Type', 'application/pdf');
});
