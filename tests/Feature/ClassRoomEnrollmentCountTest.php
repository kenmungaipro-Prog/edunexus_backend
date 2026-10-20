<?php

use App\Models\AcademicSession;
use App\Models\ClassRoom;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('class enrollment counts separate active and inactive assigned students and exclude deleted and alumni records', function () {
    $school = School::factory()->create();
    $session = AcademicSession::factory()->create([
        'school_id' => $school->id,
        'is_current' => true,
    ]);
    $otherSession = AcademicSession::factory()->create([
        'school_id' => $school->id,
        'is_current' => false,
    ]);
    $class = ClassRoom::factory()->create([
        'school_id' => $school->id,
        'session_id' => $session->id,
    ]);
    $user = User::factory()->create([
        'school_id' => $school->id,
        'role' => 'admin',
        'status' => 'active',
    ]);

    Student::factory()->create([
        'school_id' => $school->id,
        'session_id' => $session->id,
        'class_id' => $class->id,
        'status' => 'active',
    ]);
    Student::factory()->create([
        'school_id' => $school->id,
        'session_id' => $session->id,
        'class_id' => $class->id,
        'status' => 'inactive',
    ]);
    Student::factory()->create([
        'school_id' => $school->id,
        'session_id' => $otherSession->id,
        'class_id' => $class->id,
        'status' => 'active',
    ]);
    Student::factory()->create([
        'school_id' => School::factory()->create()->id,
        'session_id' => $session->id,
        'class_id' => $class->id,
        'status' => 'active',
    ]);
    Student::factory()->create([
        'school_id' => $school->id,
        'session_id' => $session->id,
        'class_id' => $class->id,
        'status' => 'alumni',
    ]);
    Student::factory()->create([
        'school_id' => $school->id,
        'session_id' => $session->id,
        'class_id' => $class->id,
        'status' => 'active',
    ])->delete();

    $this->actingAs($user)
        ->getJson("/api/v1/classes/{$class->id}")
        ->assertOk()
        ->assertJsonPath('data.students_count', 3)
        ->assertJsonPath('data.active_students_count', 2)
        ->assertJsonPath('data.inactive_students_count', 1);

    $this->actingAs($user)
        ->getJson('/api/v1/classes')
        ->assertOk()
        ->assertJsonPath('data.0.students_count', 3)
        ->assertJsonPath('data.0.active_students_count', 2)
        ->assertJsonPath('data.0.inactive_students_count', 1);
});
