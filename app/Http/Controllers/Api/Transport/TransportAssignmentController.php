<?php

namespace App\Http\Controllers\Api\Transport;

use App\Http\Controllers\Controller;
use App\Models\Transport\TransportRoute;
use App\Models\Student;
use App\Services\Transport\AssignmentService;
use App\Services\Transport\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TransportAssignmentController extends Controller
{
    protected AssignmentService $assignmentService;
    protected NotificationService $notificationService;

    public function __construct(AssignmentService $assignmentService, NotificationService $notificationService)
    {
        $this->assignmentService = $assignmentService;
        $this->notificationService = $notificationService;
    }

    public function assignStudent(Request $request, $routeId): JsonResponse
    {
        $route = TransportRoute::where('school_id', currentSchoolId())->findOrFail($routeId);

        $validated = $request->validate([
            'student_id' => [
                'required',
                'integer',
                Rule::exists('students', 'id')->where(fn ($query) => $query->where('school_id', currentSchoolId())),
            ],
            'stop' => 'required|string|max:255',
        ]);

        $this->assignmentService->assignStudentToRoute($route, $validated['student_id'], $validated['stop']);

        return response()->json(['success' => true, 'message' => 'Student successfully assigned to route.']);
    }

    public function reportPickupStatus(Request $request, $routeId): JsonResponse
    {
        $validated = $request->validate([
            'student_id' => [
                'required',
                'integer',
                Rule::exists('students', 'id')->where(fn ($query) => $query->where('school_id', currentSchoolId())),
            ],
            'status' => 'required|in:picked_up,dropped_off,absent',
        ]);

        $user = auth()->user();
        if (! $user || ! $user->isDriver() || ! $user->driver) {
            return response()->json(['success' => false, 'message' => 'Unauthorized driver.'], 403);
        }

        $route = TransportRoute::where('school_id', currentSchoolId())
            ->where('driver_id', $user->driver->id)
            ->findOrFail($routeId);

        $student = Student::with('parentProfile')
            ->where('school_id', currentSchoolId())
            ->findOrFail($validated['student_id']);

        if (! $this->assignmentService->studentAssignedToRoute($route, $student->id)) {
            return response()->json(['success' => false, 'message' => 'Student is not assigned to this route.'], 422);
        }

        $stopName = $route->students()->where('student_id', $student->id)->first()->pivot->stop;
        $statusText = match ($validated['status']) {
            'picked_up' => 'has been picked up',
            'dropped_off' => 'has been dropped off',
            'absent' => 'was marked absent',
        };

        $message = "Route {$route->name}: {$student->getFullNameAttribute()} {$statusText} at stop {$stopName}.";
        $this->notificationService->sendSmsNotifications(
            $route->school_id,
            [$student->parentProfile?->phone],
            $message
        );

        return response()->json(['success' => true, 'message' => 'Pickup status recorded and parent alerted.']);
    }
}
