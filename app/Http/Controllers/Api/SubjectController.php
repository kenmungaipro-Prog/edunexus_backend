<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subject;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

// ============================================================
// SubjectController
// ============================================================

class SubjectController extends Controller
{
    public function index(): JsonResponse
    {
        $subjects = Subject::where('school_id', currentSchoolId())
            ->withCount('exams')
            ->withCount('teachers')
            ->orderBy('name')
            ->get();

        return response()->json(['success' => true, 'data' => $subjects]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:100',
            'code' => 'nullable|string|max:20|unique:subjects,code',
            'type' => 'required|in:core,elective,activity',
        ]);

        $subject = Subject::create([
            ...$request->validated(),
            'school_id' => currentSchoolId(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Subject created.',
            'data'    => $subject,
        ], 201);
    }

    public function show(Subject $subject): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $subject->load('teachers.user', 'classRooms'),
        ]);
    }

    public function update(Request $request, Subject $subject): JsonResponse
    {
        $request->validate([
            'name' => 'sometimes|string|max:100',
            'code' => 'nullable|string|max:20|unique:subjects,code,' . $subject->id,
            'type' => 'sometimes|in:core,elective,activity',
        ]);

        $subject->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Subject updated.',
            'data'    => $subject->fresh(),
        ]);
    }

    public function destroy(Subject $subject): JsonResponse
    {
        if ($subject->exams()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete subject with associated exams.',
            ], 422);
        }

        $subject->delete();

        return response()->json(['success' => true, 'message' => 'Subject deleted.']);
    }
}
