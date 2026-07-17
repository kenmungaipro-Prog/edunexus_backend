<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{TimetableSlot, ClassRoom, Subject, Teacher, Book, BookIssue, Route, Event, Message, User};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\DB;

// ============================================================
// ClassRoomController
// ============================================================

class ClassRoomController extends Controller
{
    public function index(): JsonResponse
    {
        $classes = ClassRoom::with(['classTeacher.user'])
            ->withCount('students')
            ->orderBy('grade')
            ->orderBy('section')
            ->get();

        return response()->json(['success' => true, 'data' => $classes]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'            => 'required|string|max:50',
            'grade'           => 'required|integer|between:1,12',
            'section'         => 'required|string|max:5',
            'class_teacher_id'=> 'nullable|exists:teachers,id',
            'capacity'        => 'required|integer|min:1',
            'room'            => 'nullable|string|max:50',
            'subjects'        => 'array',
            'subjects.*'      => 'exists:subjects,id',
        ]);

        // 1. Grab the validated data
        $data = $request->except('subjects');
        
        // 2. Inject the missing school_id (using 1 as a fallback for local testing)
        $data['school_id'] = $request->user()->school_id ?? 1;
        
        // 3. Find and inject the active academic session for this school
        $activeSession = \App\Models\AcademicSession::where('school_id', $data['school_id'])
                            ->where('is_current', true)
                            ->first();
                            
        $data['session_id'] = $activeSession ? $activeSession->id : 1;

        // 4. Create the class
        $class = ClassRoom::create($data);

        // 5. Attach subjects
        if ($request->subjects) {
            $class->subjects()->sync($request->subjects);
        }

        return response()->json(['success' => true, 'data' => $class->load('classTeacher.user')], 201);
    }

    public function show(ClassRoom $class): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $class->load(['students', 'classTeacher.user', 'subjects', 'timetableSlots']),
        ]);
    }

    public function update(Request $request, ClassRoom $class): JsonResponse
    {
        $request->validate([
            'name'             => 'sometimes|required|string|max:50',
            'grade'            => 'sometimes|required|integer|between:1,12',
            'section'          => 'sometimes|required|string|max:5',
            'class_teacher_id' => 'nullable|exists:teachers,id',
            'capacity'         => 'sometimes|required|integer|min:1',
            'room'             => 'nullable|string|max:50',
            'subjects'         => 'sometimes|array',
            'subjects.*'       => 'exists:subjects,id',
        ]);

        $class->update($request->only(['name', 'grade', 'section', 'class_teacher_id', 'capacity', 'room']));

        if ($request->has('subjects')) {
            $class->subjects()->sync($request->subjects);
        }

        return response()->json(['success' => true, 'data' => $class->fresh(['classTeacher.user', 'subjects'])]);
    }

    public function destroy(ClassRoom $class): JsonResponse
    {
        if ($class->students()->exists()) {
            return response()->json(['success' => false, 'message' => 'Cannot delete class with enrolled students.'], 422);
        }
        $class->delete();
        return response()->json(['success' => true, 'message' => 'Class deleted.']);
    }
}
