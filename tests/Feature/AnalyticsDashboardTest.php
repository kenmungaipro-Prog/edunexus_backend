<?php

use App\Models\AcademicSession;
use App\Models\Attendance;
use App\Models\ClassRoom;
use App\Models\Exam;
use App\Models\Fee;
use App\Models\FeeType;
use App\Models\Grade;
use App\Models\School;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
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
        'grade' => 4,
    ]);
    $this->student = Student::factory()->create([
        'school_id' => $this->school->id,
        'session_id' => $this->session->id,
        'class_id' => $this->class->id,
    ]);
    $this->admin = User::factory()->create([
        'school_id' => $this->school->id,
        'role' => 'admin',
        'status' => 'active',
    ]);
});

it('scopes fee analytics to the school and the current year', function () {
    $localType = FeeType::factory()->create([
        'school_id' => $this->school->id,
        'name' => 'Tuition',
    ]);
    $localFee = Fee::factory()->create([
        'student_id' => $this->student->id,
        'fee_type_id' => $localType->id,
        'session_id' => $this->session->id,
        'collected_by' => $this->admin->id,
        'amount' => 1250,
        'status' => 'paid',
        'paid_at' => now(),
    ]);
    Fee::factory()->create([
        'student_id' => $this->student->id,
        'fee_type_id' => $localType->id,
        'session_id' => $this->session->id,
        'collected_by' => $this->admin->id,
        'amount' => 500,
        'status' => 'pending',
        'paid_at' => null,
    ]);
    Fee::factory()->create([
        'student_id' => $this->student->id,
        'fee_type_id' => $localType->id,
        'session_id' => $this->session->id,
        'collected_by' => $this->admin->id,
        'amount' => 770,
        'status' => 'paid',
        'paid_at' => now()->subYear(),
        'created_at' => now()->subYear(),
    ]);

    $otherSchool = School::factory()->create();
    $otherSession = AcademicSession::factory()->create(['school_id' => $otherSchool->id]);
    $otherClass = ClassRoom::factory()->create([
        'school_id' => $otherSchool->id,
        'session_id' => $otherSession->id,
    ]);
    $otherStudent = Student::factory()->create([
        'school_id' => $otherSchool->id,
        'session_id' => $otherSession->id,
        'class_id' => $otherClass->id,
    ]);
    $otherType = FeeType::factory()->create(['school_id' => $otherSchool->id]);
    Fee::factory()->create([
        'student_id' => $otherStudent->id,
        'fee_type_id' => $otherType->id,
        'session_id' => $otherSession->id,
        'collected_by' => $this->admin->id,
        'amount' => 9000,
        'status' => 'paid',
        'paid_at' => now(),
    ]);

    $response = $this->actingAs($this->admin)->getJson('/api/v1/analytics/fees');

    $response->assertOk()
        ->assertJsonPath('data.totals.paid', 1250)
        ->assertJsonPath('data.totals.pending', 500)
        ->assertJsonPath('data.by_type.0.name', 'Tuition')
        ->assertJsonPath('data.by_type.0.collected', 1250);

    expect($localFee->fresh()->amount)->toBe('1250.00');
});

it('calculates attendance using present and late records only', function () {
    $statuses = ['present', 'late', 'absent', 'holiday', 'excused'];

    foreach ($statuses as $index => $status) {
        Attendance::create([
            'student_id' => $this->student->id,
            'class_id' => $this->class->id,
            'marked_by' => $this->admin->id,
            'date' => now()->startOfMonth()->addDays($index)->toDateString(),
            'status' => $status,
        ]);
    }

    $this->actingAs($this->admin)
        ->getJson('/api/v1/analytics/overview')
        ->assertOk()
        ->assertJsonPath('data.avg_attendance', 66.7)
        ->assertJsonPath('data.pass_rate', null)
        ->assertJsonPath('data.fee_collection', null);
});

it('provides actual teacher dashboard metrics and correctly ranked students', function () {
    $teacherUser = User::factory()->create([
        'school_id' => $this->school->id,
        'role' => 'teacher',
        'status' => 'active',
    ]);
    $teacher = Teacher::where('user_id', $teacherUser->id)->firstOrFail();
    $this->class->update(['class_teacher_id' => $teacher->id]);

    $secondStudent = Student::factory()->create([
        'school_id' => $this->school->id,
        'session_id' => $this->session->id,
        'class_id' => $this->class->id,
    ]);
    $otherClass = ClassRoom::factory()->create([
        'school_id' => $this->school->id,
        'session_id' => $this->session->id,
        'grade' => 5,
    ]);
    $otherClassStudent = Student::factory()->create([
        'school_id' => $this->school->id,
        'session_id' => $this->session->id,
        'class_id' => $otherClass->id,
    ]);
    Attendance::create([
        'student_id' => $otherClassStudent->id,
        'class_id' => $otherClass->id,
        'marked_by' => $this->admin->id,
        'date' => now()->toDateString(),
        'status' => 'present',
    ]);
    $subject = Subject::create([
        'school_id' => $this->school->id,
        'name' => 'Mathematics',
        'type' => 'core',
    ]);
    $exam = Exam::create([
        'class_id' => $this->class->id,
        'subject_id' => $subject->id,
        'session_id' => $this->session->id,
        'created_by' => $this->admin->id,
        'title' => 'Term Exam',
        'exam_date' => today(),
        'start_time' => '09:00:00',
        'end_time' => '10:00:00',
        'total_marks' => 100,
        'passing_marks' => 50,
        'status' => 'completed',
    ]);

    foreach ([[$this->student, 92], [$secondStudent, 67]] as [$student, $percentage]) {
        Grade::create([
            'exam_id' => $exam->id,
            'student_id' => $student->id,
            'class_id' => $this->class->id,
            'entered_by' => $this->admin->id,
            'marks_obtained' => $percentage,
            'total_marks' => 100,
            'percentage' => $percentage,
            'letter_grade' => $percentage >= 90 ? 'A' : 'C',
            'status' => $percentage >= 50 ? 'pass' : 'fail',
        ]);
    }

    $this->actingAs($teacherUser)
        ->getJson('/api/v1/dashboard/stats')
        ->assertOk()
        ->assertJsonPath('data.classes_count', 1)
        ->assertJsonPath('data.my_students_count', 2)
        ->assertJsonPath('data.avg_attendance', null)
        ->assertJsonPath('data.class_distribution.0.students', 2)
        ->assertJsonPath('data.top_performers.0.id', $this->student->id)
        ->assertJsonPath('data.top_performers.0.percentage', 92);

    $this->actingAs($this->admin)
        ->getJson('/api/v1/dashboard/stats')
        ->assertOk()
        ->assertJsonPath('data.students', 3)
        ->assertJsonPath('data.classes_count', 0)
        ->assertJsonPath('data.class_distribution.0.students', 2)
        ->assertJsonPath('data.top_performers.0.id', $this->student->id);

    $this->actingAs($this->admin)
        ->getJson('/api/v1/analytics/grades')
        ->assertOk()
        ->assertJsonPath('data.0.students', 2)
        ->assertJsonPath('data.0.avg', 79.5);

    $this->getJson('/api/v1/analytics/exams')
        ->assertOk()
        ->assertJsonPath('data.stats.completed', 1)
        ->assertJsonPath('data.grades.0.count', 1)
        ->assertJsonPath('data.subjects.0.pass', 100);
});
