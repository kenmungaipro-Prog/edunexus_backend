<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AcademicSessionController extends Controller
{
    public function index(): JsonResponse
    {
        $sessions = AcademicSession::where('school_id', currentSchoolId())
            ->orderByDesc('is_current')
            ->orderByDesc('start_date')
            ->get();

        return response()->json(['success' => true, 'data' => $sessions]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'is_active' => 'sometimes|boolean',
        ]);

        $session = AcademicSession::create([
            ...$data,
            'school_id' => currentSchoolId(),
        ]);

        return response()->json(['success' => true, 'data' => $session], 201);
    }

    public function show(AcademicSession $academicSession): JsonResponse
    {
        if ($academicSession->school_id !== currentSchoolId()) {
            abort(404);
        }

        return response()->json(['success' => true, 'data' => $academicSession]);
    }

    public function update(Request $request, AcademicSession $academicSession): JsonResponse
    {
        if ($academicSession->school_id !== currentSchoolId()) {
            abort(404);
        }

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'start_date' => 'sometimes|required|date',
            'end_date' => 'sometimes|required|date|after:start_date',
            'is_active' => 'sometimes|boolean',
        ]);

        $academicSession->update($data);

        return response()->json(['success' => true, 'data' => $academicSession]);
    }

    public function destroy(AcademicSession $academicSession): JsonResponse
    {
        if ($academicSession->school_id !== currentSchoolId()) {
            abort(404);
        }

        // Check if session is in use
        if ($academicSession->students()->exists() ||
            $academicSession->classRooms()->exists() ||
            $academicSession->exams()->exists()) {
            return response()->json(['success' => false, 'message' => 'Cannot delete: session in use'], 422);
        }

        $academicSession->delete();

        return response()->json(['success' => true, 'data' => null]);
    }
}