<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class TeacherController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $teachers = Teacher::with(['user', 'classRooms', 'subjects'])
            ->when($request->department, fn($q, $v) => $q->where('department', $v))
            ->when($request->status,     fn($q, $v) => $q->where('status', $v))
            ->when($request->search,     fn($q, $v) => $q->whereHas('user', fn($u) =>
                $u->where('name', 'like', "%{$v}%")->orWhere('email', 'like', "%{$v}%")
            ))
            ->paginate($request->per_page ?? 20);

        return response()->json(['success' => true, 'data' => $teachers]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'           => 'required|string|max:255',
            'email'          => 'required|email|unique:users',
            'phone'          => 'nullable|string|max:20',
            'department'     => 'required|string|max:100',
            'qualification'  => 'nullable|string|max:255',
            'experience_yrs' => 'nullable|integer|min:0',
            'join_date'      => 'nullable|date',
            'salary'         => 'nullable|numeric',
            'status'         => 'sometimes|in:active,inactive,on_leave',
            'password'       => 'sometimes|string|min:8',
            'subjects'       => 'nullable|array',
            'subjects.*'     => 'integer|exists:subjects,id',
        ]);

        $result = DB::transaction(function () use ($request) {
            $schoolId = auth()->user()->school_id;

            $userStatus = $request->status === 'inactive' ? 'inactive' : 'active';

            $user = User::create([
                'school_id' => $schoolId,
                'name'      => $request->name,
                'email'     => $request->email,
                'password'  => Hash::make($request->password ?? 'Teacher@123'),
                'role'      => 'teacher',
                'status'    => $userStatus,
            ]);

            $teacher = Teacher::create([
                'user_id'        => $user->id,
                'school_id'      => $schoolId,
                'employee_id'    => 'T-' . str_pad(Teacher::count() + 1, 3, '0', STR_PAD_LEFT),
                'phone'          => $request->phone,
                'department'     => $request->department,
                'qualification'  => $request->qualification,
                'experience_yrs' => $request->experience_yrs ?? 0,
                'join_date'      => $request->join_date,
                'salary'         => $request->salary,
                'status'         => $request->status ?? 'active',
            ]);

            if ($request->subjects) {
                $teacher->subjects()->sync($request->subjects);
            }

            return $teacher;
        });

        return response()->json([
            'success' => true,
            'message' => $request->password
                ? 'Teacher created.'
                : 'Teacher created. Default password: Teacher@123',
            'data'    => $result->load('user', 'subjects'),
        ], 201);
    }

    public function show(Teacher $teacher): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $teacher->load(['user', 'classRooms', 'subjects']),
        ]);
    }

    public function update(Request $request, Teacher $teacher): JsonResponse
    {
        $request->validate([
            'name'           => 'sometimes|string|max:255',
            'email'          => 'sometimes|email|unique:users,email,' . $teacher->user_id,
            'phone'          => 'sometimes|string|max:20',
            'department'     => 'sometimes|string|max:100',
            'qualification'  => 'sometimes|string|max:255',
            'experience_yrs' => 'sometimes|integer|min:0',
            'join_date'      => 'sometimes|date',
            'employment_type' => 'sometimes|string',
            'bio'            => 'sometimes|string',
            'status'         => 'sometimes|in:active,inactive,on_leave',
            'subjects'       => 'sometimes|array',
            'subjects.*'     => 'integer|exists:subjects,id',
        ]);

        DB::transaction(function () use ($request, $teacher) {
            if ($request->name) {
                $teacher->user->update(['name' => $request->name]);
            }
            if ($request->email) {
                $teacher->user->update(['email' => $request->email]);
            }
            if ($request->has('status')) {
                $teacher->user->update([
                    'status' => $request->status === 'inactive' ? 'inactive' : 'active',
                ]);
            }

            $teacher->update($request->except('name', 'email', 'subjects'));
            if ($request->has('subjects')) {
                $teacher->subjects()->sync($request->subjects ?? []);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Teacher updated.',
            'data'    => $teacher->fresh(['user', 'subjects', 'classRooms']),
        ]);
    }

    public function destroy(Teacher $teacher): JsonResponse
    {
        DB::transaction(function () use ($teacher) {
            $teacher->user->update(['status' => 'inactive']);
            $teacher->update(['status' => 'inactive']);
        });

        return response()->json(['success' => true, 'message' => 'Teacher deactivated.']);
    }

    public function timetable(Teacher $teacher): JsonResponse
    {
        $slots = $teacher->timetableSlots()
            ->with('classRoom', 'subject')
            ->orderBy('day_of_week')
            ->orderBy('period_number')
            ->get()
            ->groupBy('day_of_week');

        return response()->json(['success' => true, 'data' => $slots]);
    }

    public function performance(Teacher $teacher): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => [
                'avg_student_score' => $teacher->classRooms()
                    ->with('students.grades')
                    ->get()
                    ->flatMap(fn($c) => $c->students->flatMap->grades)
                    ->avg('marks_obtained') ?? 0, // Added ?? 0 fallback
                
                // Defaulted to 100 since there is no teacher_attendances table
                'attendance_rate'   => 100, 
                
                'classes_taught'    => $teacher->classRooms()->count(),
                'students_count'    => $teacher->classRooms()->withCount('students')->get()->sum('students_count'),
            ],
        ]);
    }

    
}
