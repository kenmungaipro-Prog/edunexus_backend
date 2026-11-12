<?php

use App\Models\AcademicSession;
use App\Models\ClassRoom;
use App\Models\ParentProfile;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->school = School::factory()->create();
    $this->session = AcademicSession::factory()->create([
        'school_id' => $this->school->id,
        'is_current' => true,
    ]);
    $this->class = ClassRoom::factory()->create([
        'school_id' => $this->school->id,
        'session_id' => $this->session->id,
    ]);
    $this->admin = User::factory()->create([
        'school_id' => $this->school->id,
        'role' => 'admin',
        'status' => 'active',
    ]);
});

function createParentProfileForTest(int $schoolId): ParentProfile
{
    $user = User::factory()->create([
        'school_id' => $schoolId,
        'role' => 'parent',
        'status' => 'active',
    ]);

    return ParentProfile::create([
        'school_id' => $schoolId,
        'user_id' => $user->id,
    ]);
}

test('deleting a parent can soft-delete linked students and chosen co-parents', function () {
    $parent = createParentProfileForTest($this->school->id);
    $coParent = createParentProfileForTest($this->school->id);
    $student = Student::factory()->create([
        'school_id' => $this->school->id,
        'class_id' => $this->class->id,
        'session_id' => $this->session->id,
        'parent_id' => $parent->user_id,
        'secondary_parent_id' => $coParent->user_id,
    ]);

    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/parents/{$parent->id}?delete_linked_students=1&delete_other_parents=1")
        ->assertOk();

    $this->assertSoftDeleted('students', ['id' => $student->id]);
    $this->assertDatabaseMissing('parent_profiles', ['id' => $parent->id]);
    $this->assertDatabaseMissing('parent_profiles', ['id' => $coParent->id]);
    $this->assertDatabaseHas('users', ['id' => $parent->user_id, 'status' => 'inactive']);
    $this->assertDatabaseHas('users', ['id' => $coParent->user_id, 'status' => 'inactive']);
});

test('deleting a parent without cascade choices preserves linked students and co-parents', function () {
    $parent = createParentProfileForTest($this->school->id);
    $coParent = createParentProfileForTest($this->school->id);
    $student = Student::factory()->create([
        'school_id' => $this->school->id,
        'class_id' => $this->class->id,
        'session_id' => $this->session->id,
        'parent_id' => $parent->user_id,
        'secondary_parent_id' => $coParent->user_id,
    ]);

    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/parents/{$parent->id}")
        ->assertOk();

    $this->assertDatabaseHas('students', ['id' => $student->id, 'deleted_at' => null]);
    $this->assertDatabaseMissing('parent_profiles', ['id' => $parent->id]);
    $this->assertDatabaseHas('parent_profiles', ['id' => $coParent->id]);
    $this->assertDatabaseHas('users', ['id' => $coParent->user_id, 'status' => 'active']);
});
