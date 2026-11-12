<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StudentRequest;
use App\Models\Student;
use App\Models\ParentProfile;
use App\Models\School;
use App\Models\User;
use App\Models\AcademicSession;
use App\Models\Invoice;
use App\Models\Payment;
use App\Imports\StudentsImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class StudentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $students = Student::with(['classRoom', 'parent', 'secondaryParent'])
            ->where('school_id', currentSchoolId())
            ->when($request->class_id, fn ($q, $v) => $q->where('class_id', $v))
            ->when($request->status,   fn ($q, $v) => $q->where('status', $v))
            ->when($request->gender,   fn ($q, $v) => $q->where('gender', $v))
            ->when($request->search,   fn ($q, $v) => $q->where(function ($q) use ($v) {
                $q->where('first_name',  'like', "%{$v}%")
                  ->orWhere('last_name',   'like', "%{$v}%")
                  ->orWhere('admission_no','like', "%{$v}%")
                  ->orWhere('roll_number', 'like', "%{$v}%");
            }))
            ->orderBy($request->sort_by  ?? 'first_name', $request->sort_dir ?? 'asc')
            ->paginate($request->per_page ?? 20);

        $pagination = $students->toArray();

        return response()->json([
            'success' => true,
            'data' => [
                'data' => $pagination['data'],
                'meta' => $pagination['meta'] ?? [],
                'links' => $pagination['links'] ?? [],
            ],
        ]);
    }

    public function checkAdmissionNumber(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'admission_no' => 'required|string|max:255',
        ]);

        $student = Student::withTrashed()
            ->with('classRoom:id,name')
            ->where('admission_no', trim($validated['admission_no']))
            ->first();

        if (! $student) {
            return response()->json([
                'success' => true,
                'data' => ['exists' => false, 'student' => null],
            ]);
        }

        if ($student->school_id !== currentSchoolId()) {
            return response()->json([
                'success' => true,
                'data' => [
                    'exists' => true,
                    'student' => null,
                    'message' => 'This admission number is already in use and cannot be reused.',
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'exists' => true,
                'student' => [
                    'id' => $student->id,
                    'full_name' => $student->full_name,
                    'admission_no' => $student->admission_no,
                    'admission_date' => $student->admission_date?->toDateString(),
                    'class_name' => $student->classRoom?->name,
                    'status' => $student->trashed() ? 'deleted' : $student->status,
                ],
                'message' => 'This admission number already exists in EduNexus.',
            ],
        ]);
    }

    public function store(StudentRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $student = DB::transaction(function () use ($request, $validated) {
            $school = School::whereKey(currentSchoolId())->lockForUpdate()->firstOrFail();

            $parentId = $this->resolveParent($request, 'parent_id', 'parent_email', 'parent_name', 'parent_phone');
            $secondaryParentId = $this->resolveParent($request, 'secondary_parent_id', 'secondary_parent_email', 'secondary_parent_name', 'secondary_parent_phone');
            $emergencyContactId = $request->filled('emergency_contact_parent_id')
                ? $request->emergency_contact_parent_id
                : null;

            unset($validated['admission_no'], $validated['existing_student']);
            $student = Student::create([
                ...$validated,
                'school_id'    => currentSchoolId(),
                'session_id'   => $request->session_id ?? currentSession(),
                'parent_id'    => $parentId,
                'secondary_parent_id' => $secondaryParentId,
                'emergency_contact_parent_id' => $emergencyContactId,
                'admission_no' => $request->boolean('existing_student')
                    ? $request->input('admission_no')
                    : $this->generateAdmissionNo($school),
                'roll_number'  => $this->generateRollNumber($request->class_id),
            ]);

            if ($request->hasFile('profile_photo')) {
                $path = $request->file('profile_photo')
                    ->store("students/photos/{$student->id}", 'public');
                $student->update(['profile_photo' => $path]);
            }

            return $student;
        });

        return response()->json([
            'success' => true,
            'message' => 'Student created successfully.',
            'data'    => $student->load(['classRoom', 'parent', 'secondaryParent', 'emergencyContactParent']),
        ], 201);
    }

    public function show(Student $student): JsonResponse
    {
        $this->authorizeSchool($student);

        return response()->json([
            'success' => true,
            'data'    => $student->load([
                'classRoom',
                'parent',
                'secondaryParent',
                'attendance',
                'fees.feeType',
                'grades.exam.subject',
            ]),
        ]);
    }

    public function update(StudentRequest $request, Student $student): JsonResponse
    {
        $this->authorizeSchool($student);

        if ($request->filled('parent_id')) {
            $student->parent_id = $request->parent_id;
        }
        if ($request->filled('secondary_parent_id')) {
            $student->secondary_parent_id = $request->secondary_parent_id;
        }
        if ($request->filled('emergency_contact_parent_id')) {
            $student->emergency_contact_parent_id = $request->emergency_contact_parent_id;
        }

        $student->update($request->validated());

        if ($request->hasFile('profile_photo')) {
            $path = $request->file('profile_photo')
                ->store("students/photos/{$student->id}", 'public');
            $student->update(['profile_photo' => $path]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Student updated.',
            'data'    => $student->fresh(['classRoom', 'parent', 'secondaryParent']),
        ]);
    }

    public function destroy(Request $request, Student $student): JsonResponse
    {
        $this->authorizeSchool($student);

        $validated = $request->validate([
            'remove_orphaned_parents' => ['sometimes', 'boolean'],
        ]);
        $parentIds = array_filter(array_unique([
            $student->parent_id,
            $student->secondary_parent_id,
            $student->emergency_contact_parent_id,
        ]));
        $removedParents = 0;

        DB::transaction(function () use ($student, $parentIds, $validated, &$removedParents) {
            $student->delete();

            if (!($validated['remove_orphaned_parents'] ?? false)) {
                return;
            }

            foreach ($parentIds as $parentId) {
                $hasOtherActiveStudents = Student::where('school_id', $student->school_id)
                    ->where(function ($query) use ($parentId) {
                        $query->where('parent_id', $parentId)
                            ->orWhere('secondary_parent_id', $parentId)
                            ->orWhere('emergency_contact_parent_id', $parentId);
                    })
                    ->exists();

                if ($hasOtherActiveStudents) {
                    continue;
                }

                $profile = ParentProfile::where('school_id', $student->school_id)
                    ->where('user_id', $parentId)
                    ->first();

                if (!$profile) {
                    continue;
                }

                $profile->user()->update(['status' => 'inactive']);
                $profile->delete();
                $removedParents++;
            }
        });

        return response()->json([
            'success' => true,
            'message' => $removedParents > 0
                ? "Student removed; {$removedParents} unshared parent account(s) deactivated."
                : 'Student removed.',
        ]);
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,csv,xls|max:5120',
        ]);

        $import = new StudentsImport(currentSchoolId(), currentSession());
        Excel::import($import, $request->file('file'));

        return response()->json([
            'success'  => true,
            'message'  => "Imported {$import->getRowCount()} students successfully.",
            'failures' => $import->failures(),
        ]);
    }

    public function reportCard(Student $student): JsonResponse
    {
        $this->authorizeSchool($student);

        $grades = $student->grades()
            ->with('exam.subject')
            ->whereHas('exam', fn ($q) => $q->where('session_id', currentSession()))
            ->get()
            ->groupBy('exam.subject.name');

        $rank = Student::where('class_id', $student->class_id)
            ->get()
            ->sortByDesc(fn ($s) => $s->grades()->avg('percentage'))
            ->values()
            ->search(fn ($s) => $s->id === $student->id);

        return response()->json([
            'success' => true,
            'data'    => [
                'student'            => $student->load('classRoom'),
                'grades'             => $grades,
                'attendance_percentage' => $student->attendance_percentage,
                'rank'               => ($rank !== false) ? $rank + 1 : null,
                'total_students'     => Student::where('class_id', $student->class_id)->count(),
            ],
        ]);
    }

    public function attendanceHistory(Student $student, Request $request): JsonResponse
    {
        $this->authorizeSchool($student);

        $history = $student->attendance()
            ->when($request->month, fn ($q, $v) => $q->whereMonth('date', $v))
            ->when($request->year,  fn ($q, $v) => $q->whereYear('date',  $v))
            ->orderByDesc('date')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => [
                'records'    => $history,
                'percentage' => $student->attendance_percentage,
                'summary'    => [
                    'present' => $history->where('status', 'present')->count(),
                    'absent'  => $history->where('status', 'absent')->count(),
                    'late'    => $history->where('status', 'late')->count(),
                    'holiday' => $history->where('status', 'holiday')->count(),
                ],
            ],
        ]);
    }

    public function feeHistory(Student $student): JsonResponse
    {
        $this->authorizeSchool($student);

        $fees = $student->fees()
            ->with('feeType', 'collectedBy')
            ->orderByDesc('created_at')
            ->get();

        $invoices = Invoice::where('school_id', currentSchoolId())
            ->where('student_id', $student->id)
            ->orderByDesc('created_at')
            ->get();

        $payments = Payment::with(['allocations.invoice', 'receipt'])
            ->where('school_id', currentSchoolId())
            ->where('student_id', $student->id)
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => [
                'fees'         => $fees,
                'invoices'     => $invoices,
                'payments'     => $payments,
                'total_paid'   => $fees->where('status', 'paid')->sum('amount'),
                'total_pending'=> $fees->where('status', 'pending')->sum('amount'),
                'total_overdue'=> $fees->where('status', 'overdue')->sum('amount'),
            ],
        ]);
    }

    public function stats(): JsonResponse
    {
        $schoolId = currentSchoolId();

        $total    = Student::where('school_id', $schoolId)->count();
        $active   = Student::where('school_id', $schoolId)->where('status', 'active')->count();
        $inactive = Student::where('school_id', $schoolId)->where('status', 'inactive')->count();
        $alumni   = Student::where('school_id', $schoolId)->where('status', 'alumni')->count();

        // Fee overdue: students with at least one overdue fee record.
        $feeOverdue = Student::where('school_id', $schoolId)
            ->whereHas('fees', fn ($q) => $q->where('status', 'overdue'))
            ->count();

        // Derive table name from the Attendance model — never hard-code it.
        $attTable = (new \App\Models\Attendance())->getTable();
        $lowAttendance = Student::where('school_id', $schoolId)
            ->whereRaw("(
                SELECT CASE WHEN COUNT(*) = 0 THEN 0
                       ELSE ROUND(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 1)
                       END
                FROM   `{$attTable}`
                WHERE  `{$attTable}`.student_id = students.id
            ) < 80", ['present'])
            ->count();

        return response()->json([
            'success' => true,
            'data'    => [
                'total'          => $total,
                'active'         => $active,
                'inactive'       => $inactive,
                'alumni'         => $alumni,
                'fee_overdue'    => $feeOverdue,
                'low_attendance' => $lowAttendance,
            ],
        ]);
    }

    private function generateAdmissionNo(School $school): string
    {
        $prefix = strtoupper($school->admission_prefix ?: 'SPSK');
        $admissionNumbers = Student::withTrashed()
            ->where('admission_no', 'like', "{$prefix}-%")
            ->pluck('admission_no');

        $lastNumber = $admissionNumbers->reduce(function (int $highest, string $admissionNumber) use ($prefix) {
            if (preg_match('/^' . preg_quote($prefix, '/') . '-(\d+)$/i', $admissionNumber, $matches)) {
                return max($highest, (int) $matches[1]);
            }

            return $highest;
        }, 0);

        return $prefix . '-' . str_pad((string) ($lastNumber + 1), 4, '0', STR_PAD_LEFT);
    }

    private function generateRollNumber(int $classId): string
    {
        $count = Student::where('class_id', $classId)->count() + 1;
        return 'S-' . str_pad($count + 1000, 4, '0', STR_PAD_LEFT);
    }

    private function authorizeSchool(Student $student): void
    {
        if ($student->school_id !== currentSchoolId()) {
            abort(403, 'Access denied.');
        }
    }

    private function resolveParent($request, string $parentIdKey, string $parentEmailKey, string $parentNameKey, string $parentPhoneKey): ?int
    {
        $parentId = $request->input($parentIdKey);
        $parent = null;

        if ($parentId) {
            $parent = User::find($parentId);
        }

        if (! $parent && ($request->filled($parentEmailKey) || $request->filled($parentNameKey))) {
            $email = $request->input($parentEmailKey);
            $name = $request->input($parentNameKey) ?: 'Parent';

            if ($email) {
                $parent = User::firstOrCreate(
                    ['email' => $email],
                    [
                        'name'      => $name,
                        'password'  => Hash::make('Parent@123'),
                        'role'      => 'parent',
                        'status'    => 'active',
                        'school_id' => currentSchoolId(),
                    ]
                );
            } else {
                $generatedEmail = Str::slug($name) ?: 'parent';
                $parent = User::create([
                    'name'      => $name,
                    'email'     => $generatedEmail . '-' . uniqid() . '@no-email.local',
                    'password'  => Hash::make('Parent@123'),
                    'role'      => 'parent',
                    'status'    => 'active',
                    'school_id' => currentSchoolId(),
                ]);
            }
            $parentId = $parent->id;
        }

        if ($parent) {
            $profileData = ['school_id' => currentSchoolId()];

            if ($request->filled($parentPhoneKey)) {
                $profileData['phone'] = $request->input($parentPhoneKey);
                $profileData['whatsapp_phone'] = $request->input($parentPhoneKey);
                $profileData['preferred_whatsapp'] = true;
            }

            ParentProfile::updateOrCreate(
                ['user_id' => $parent->id],
                $profileData
            );
        }

        return $parentId;
    }
}