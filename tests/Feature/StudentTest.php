<?php
// ============================================================
// tests/Feature/StudentTest.php
// ============================================================

use App\Models\{
    Student,
    ClassRoom,
    AcademicSession,
    School,
    User,
    ParentProfile
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

test('admission number check returns matching student details for this school', function () {
    $student = Student::factory()->create([
        'school_id' => $this->school->id,
        'class_id' => $this->class->id,
        'session_id' => $this->session->id,
        'admission_no' => 'EXISTING-100',
    ]);

    $this->actingAs($this->admin)
        ->getJson('/api/v1/students/admission-number-check?admission_no=EXISTING-100')
        ->assertOk()
        ->assertJsonPath('data.exists', true)
        ->assertJsonPath('data.student.id', $student->id)
        ->assertJsonPath('data.student.full_name', $student->full_name)
        ->assertJsonPath('data.student.admission_no', 'EXISTING-100')
        ->assertJsonPath('data.student.class_name', $this->class->name);
});

test('admission number check does not reveal a student from another school', function () {
    $otherSchool = School::factory()->create();
    $otherSession = AcademicSession::factory()->create([
        'school_id' => $otherSchool->id,
        'is_current' => true,
    ]);
    $otherClass = ClassRoom::factory()->create([
        'school_id' => $otherSchool->id,
        'session_id' => $otherSession->id,
    ]);
    Student::factory()->create([
        'school_id' => $otherSchool->id,
        'class_id' => $otherClass->id,
        'session_id' => $otherSession->id,
        'admission_no' => 'PRIVATE-200',
    ]);

    $this->actingAs($this->admin)
        ->getJson('/api/v1/students/admission-number-check?admission_no=PRIVATE-200')
        ->assertOk()
        ->assertJsonPath('data.exists', true)
        ->assertJsonPath('data.student', null)
        ->assertJsonPath('data.message', 'This admission number is already in use and cannot be reused.');
});

test('admission number check reports an unused number as available', function () {
    $this->actingAs($this->admin)
        ->getJson('/api/v1/students/admission-number-check?admission_no=AVAILABLE-300')
        ->assertOk()
        ->assertJsonPath('data.exists', false)
        ->assertJsonPath('data.student', null);
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

    expect($response->json('data.admission_no'))->toBe('SPSK-0001');

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

test('generated admission numbers use the school prefix and continue its existing sequence', function () {
    $this->actingAs($this->admin)
        ->putJson('/api/v1/settings/school', ['admission_prefix' => 'spsk'])
        ->assertOk()
        ->assertJsonPath('data.admission_prefix', 'SPSK');

    Student::factory()->create([
        'school_id' => $this->school->id,
        'class_id' => $this->class->id,
        'session_id' => $this->session->id,
        'admission_no' => 'SPSK-0007',
    ])->delete();

    $response = $this->actingAs($this->admin)
        ->postJson('/api/v1/students', [
            'first_name' => 'Next',
            'last_name' => 'Student',
            'date_of_birth' => '2008-05-15',
            'gender' => 'male',
            'class_id' => $this->class->id,
            'parent_name' => 'Parent Name',
            'parent_email' => 'prefix-parent@example.com',
            'parent_phone' => '+254700000005',
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.admission_no', 'SPSK-0008');
});

test('admin can preserve an existing student admission number after confirmation', function () {
    $admissionNumber = "LEGACY-{$this->school->id}-001";

    $response = $this->actingAs($this->admin)
        ->postJson('/api/v1/students', [
            'first_name' => 'Existing',
            'last_name' => 'Student',
            'date_of_birth' => '2008-05-15',
            'gender' => 'male',
            'class_id' => $this->class->id,
            'admission_date' => now()->subYear()->toDateString(),
            'existing_student' => true,
            'admission_no' => $admissionNumber,
            'parent_name' => 'Parent Name',
            'parent_email' => "parent-{$this->school->id}@example.com",
            'parent_phone' => '+254700000001',
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.admission_no', $admissionNumber);
});

test('a manually supplied admission number requires explicit existing student confirmation', function () {
    $this->actingAs($this->admin)
        ->postJson('/api/v1/students', [
            'first_name' => 'Unconfirmed',
            'last_name' => 'Student',
            'date_of_birth' => '2008-05-15',
            'gender' => 'male',
            'class_id' => $this->class->id,
            'admission_date' => now()->subYear()->toDateString(),
            'admission_no' => "LEGACY-{$this->school->id}-unconfirmed",
            'parent_name' => 'Parent Name',
            'parent_email' => "parent-unconfirmed-{$this->school->id}@example.com",
            'parent_phone' => '+254700000004',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['admission_no']);
});

test('official admission number requires an admission date before today', function () {
    $response = $this->actingAs($this->admin)
        ->postJson('/api/v1/students', [
            'first_name' => 'New',
            'last_name' => 'Student',
            'date_of_birth' => '2008-05-15',
            'gender' => 'male',
            'class_id' => $this->class->id,
            'admission_date' => now()->toDateString(),
            'existing_student' => true,
            'admission_no' => "LEGACY-{$this->school->id}-002",
            'parent_name' => 'Parent Name',
            'parent_email' => "parent-today-{$this->school->id}@example.com",
            'parent_phone' => '+254700000002',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['admission_date']);
});

test('official admission number must be unique', function () {
    $admissionNumber = "LEGACY-{$this->school->id}-003";
    Student::factory()->create([
        'school_id' => $this->school->id,
        'class_id' => $this->class->id,
        'session_id' => $this->session->id,
        'admission_no' => $admissionNumber,
    ]);

    $response = $this->actingAs($this->admin)
        ->postJson('/api/v1/students', [
            'first_name' => 'Duplicate',
            'last_name' => 'Student',
            'date_of_birth' => '2008-05-15',
            'gender' => 'male',
            'class_id' => $this->class->id,
            'admission_date' => now()->subYear()->toDateString(),
            'existing_student' => true,
            'admission_no' => $admissionNumber,
            'parent_name' => 'Parent Name',
            'parent_email' => "parent-duplicate-{$this->school->id}@example.com",
            'parent_phone' => '+254700000003',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['admission_no']);
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

test('student deletion can remove linked parents who have no other active students', function () {
    $parentUser = User::factory()->create([
        'school_id' => $this->school->id,
        'role' => 'parent',
        'status' => 'active',
    ]);
    $parentProfile = ParentProfile::create([
        'school_id' => $this->school->id,
        'user_id' => $parentUser->id,
    ]);
    $student = Student::factory()->create([
        'school_id' => $this->school->id,
        'class_id' => $this->class->id,
        'session_id' => $this->session->id,
        'parent_id' => $parentUser->id,
    ]);

    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/students/{$student->id}?remove_orphaned_parents=1")
        ->assertOk();

    $this->assertSoftDeleted('students', ['id' => $student->id]);
    $this->assertDatabaseMissing('parent_profiles', ['id' => $parentProfile->id]);
    $this->assertDatabaseHas('users', ['id' => $parentUser->id, 'status' => 'inactive']);
});

test('student deletion keeps a parent account shared with another active student', function () {
    $parentUser = User::factory()->create([
        'school_id' => $this->school->id,
        'role' => 'parent',
        'status' => 'active',
    ]);
    $parentProfile = ParentProfile::create([
        'school_id' => $this->school->id,
        'user_id' => $parentUser->id,
    ]);
    $students = Student::factory(2)->create([
        'school_id' => $this->school->id,
        'class_id' => $this->class->id,
        'session_id' => $this->session->id,
        'parent_id' => $parentUser->id,
    ]);

    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/students/{$students[0]->id}?remove_orphaned_parents=1")
        ->assertOk();

    $this->assertDatabaseHas('parent_profiles', ['id' => $parentProfile->id]);
    $this->assertDatabaseHas('users', ['id' => $parentUser->id, 'status' => 'active']);
    $this->assertDatabaseHas('students', ['id' => $students[1]->id, 'parent_id' => $parentUser->id]);
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