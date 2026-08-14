<?php
// ============================================================
// tests/Feature/StudentTest.php
// ============================================================

use App\Models\{
    Student,
    ClassRoom,
    AcademicSession,
    School,
    User
};
use Illuminate\Foundation\Testing\RefreshDatabase;

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