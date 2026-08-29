<?php

namespace App\Http\Controllers\Api\Transport;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\Transport\{TransportRoute, TransportTrip, TransportStop, TripStudent, TripStop};
use App\Services\Transport\{TripService, TripStopService, TripStudentService};
use App\Events\TripStatusUpdated;
use App\Events\StudentStatusUpdated;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransportTripController extends Controller
{
    protected TripStopService $tripStopService;
    protected TripService $tripService;
    protected TripStudentService $tripStudentService;

    public function __construct(TripStopService $tripStopService, TripService $tripService, TripStudentService $tripStudentService)
    {
        $this->tripStopService = $tripStopService;
        $this->tripService = $tripService;
        $this->tripStudentService = $tripStudentService;
    }

    public function listTrips(): JsonResponse
    {
        $trips = $this->tripService->listTrips(currentSchoolId());

        return response()->json(['success' => true, 'data' => $trips]);
    }

    public function myTrips(): JsonResponse
    {
        $user = auth()->user();

        if (! $user || ! $user->isDriver() || ! $user->driver) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $trips = $this->tripService->listTripsForDriver(currentSchoolId(), $user->driver->id);

        return response()->json(['success' => true, 'data' => $trips]);
    }

    public function scheduleTrip(Request $request, $routeId): JsonResponse
    {
        $route = TransportRoute::where('school_id', currentSchoolId())->findOrFail($routeId);

        $validated = $request->validate([
            'direction' => 'required|in:morning,afternoon,return,other',
            'scheduled_start' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
        ]);

        $trip = $this->tripService->scheduleTrip($route, $validated);

        return response()->json(['success' => true, 'data' => $trip], 201);
    }

    public function startTrip(Request $request, $tripId): JsonResponse
    {
        $trip = $this->tripService->getTripForSchool(currentSchoolId(), $tripId);

        $user = auth()->user();

        if ($user->isDriver()) {
            if (! $user->driver || $trip->driver_id !== $user->driver->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not authorized to start this trip.',
                ], 403);
            }
        }

        try {
            $trip = $this->tripService->startTrip($trip);
            // Broadcast trip started
            try {
                event(new TripStatusUpdated($trip, 'started'));
            } catch (\Throwable $e) {
                report($e);
            }
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $trip,
        ]);
    }

    public function endTrip(Request $request, $tripId): JsonResponse
    {
        $trip = $this->tripService->getTripForSchool(currentSchoolId(), $tripId);

        $user = auth()->user();

        if ($user->isDriver()) {
            if (! $user->driver || $trip->driver_id !== $user->driver->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not authorized to end this trip.',
                ], 403);
            }
        }

        try {
            $trip = $this->tripService->completeTrip($trip);
            // Broadcast trip ended
            try {
                event(new TripStatusUpdated($trip, 'ended'));
            } catch (\Throwable $e) {
                report($e);
            }
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $trip,
        ]);
    }

    public function showTrip($tripId): JsonResponse
    {
        $trip = $this->tripService->getTripForSchool(currentSchoolId(), $tripId, ['route.vehicle', 'route.driver', 'locations']);

        return response()->json(['success' => true, 'data' => $trip]);
    }

    public function tripLocations($tripId): JsonResponse
    {
        $trip = $this->tripService->getTripForSchool(currentSchoolId(), $tripId, ['locations']);

        return response()->json(['success' => true, 'data' => $trip->locations]);
    }

    public function tripStudents($tripId): JsonResponse
    {
        $trip = $this->tripService->getTripForSchool(currentSchoolId(), $tripId);
        $students = $this->tripStudentService->listTripStudents($trip);

        return response()->json(['success' => true, 'data' => $students]);
    }

    public function storeTripStudent(Request $request, $tripId): JsonResponse
    {
        $trip = $this->tripService->getTripForSchool(currentSchoolId(), $tripId);
        $student = Student::where('school_id', currentSchoolId())->findOrFail($request->input('student_id'));

        $validated = $request->validate([
            'student_id' => 'required|exists:students,id',
            'status' => 'nullable|in:pending,cancelled',
        ]);

        try {
            $tripStudent = $this->tripStudentService->createTripStudent($trip, $student, $validated);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $tripStudent], 201);
    }

    public function updateTripStudent(Request $request, $tripId, $tripStudentId): JsonResponse
    {
        $trip = $this->tripService->getTripForSchool(currentSchoolId(), $tripId);
        $tripStudent = TripStudent::where('transport_trip_id', $trip->id)
            ->findOrFail($tripStudentId);

        $validated = $request->validate([
            'status' => 'nullable|in:pending,cancelled',
        ]);

        try {
            $tripStudent = $this->tripStudentService->updateTripStudent($tripStudent, $validated);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $tripStudent]);
    }

    public function boardTripStudent(Request $request, $tripId, $tripStudentId): JsonResponse
    {
        $trip = $this->tripService->getTripForSchool(currentSchoolId(), $tripId);
        $tripStudent = TripStudent::where('transport_trip_id', $trip->id)
            ->findOrFail($tripStudentId);

        $validated = $request->validate([
            'boarding_stop_id' => 'required|exists:transport_stops,id',
            'boarding_time' => 'required|date',
        ]);

        try {
            $tripStudent = $this->tripStudentService->boardStudent($tripStudent, $validated);
            
            // Broadcast student boarded event
            try {
                event(new StudentStatusUpdated($tripStudent->student, $trip, $tripStudent, 'boarded'));
            } catch (\Throwable $e) {
                report($e);
            }
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $tripStudent]);
    }

    public function dropOffTripStudent(Request $request, $tripId, $tripStudentId): JsonResponse
    {
        $trip = $this->tripService->getTripForSchool(currentSchoolId(), $tripId);
        $tripStudent = TripStudent::where('transport_trip_id', $trip->id)
            ->findOrFail($tripStudentId);

        $validated = $request->validate([
            'dropoff_stop_id' => 'required|exists:transport_stops,id',
            'dropoff_time' => 'required|date',
        ]);

        try {
            $tripStudent = $this->tripStudentService->dropOffStudent($tripStudent, $validated);
            
            // Broadcast student dropped-off event
            try {
                event(new StudentStatusUpdated(
                    $tripStudent->student,
                    $trip,
                    $tripStudent,
                    'dropped_off'
                ));
            } catch (\Throwable $e) {
                report($e);
            }
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $tripStudent]);
    }

    public function tripStops($tripId): JsonResponse
    {
        $trip = TransportTrip::where('school_id', currentSchoolId())
            ->with('tripStops.stop')
            ->findOrFail($tripId);

        return response()->json(['success' => true, 'data' => $trip->tripStops]);
    }

    public function storeTripStop(Request $request, $tripId, $stopId): JsonResponse
    {
        $trip = TransportTrip::where('school_id', currentSchoolId())->findOrFail($tripId);
        $stop = TransportStop::where('transport_route_id', $trip->transport_route_id)->findOrFail($stopId);

        $validated = $request->validate([
            'sequence' => 'nullable|integer|min:0',
            'arrived_at' => 'nullable|date',
            'departed_at' => 'nullable|date',
            'status' => 'nullable|in:pending,arrived,departed,cancelled',
        ]);

        $tripStop = $this->tripStopService->createTripStop($trip, $stop, $validated);

        // Broadcast trip stop update
        try {
            event(new TripStatusUpdated($trip, 'stop.updated', $tripStop));
        } catch (\Throwable $e) {
            // Do not fail the request if broadcasting fails; log and continue
            report($e);
        }

        return response()->json(['success' => true, 'data' => $tripStop], 201);
    }

    public function updateTripStop(Request $request, $tripId, $tripStopId): JsonResponse
    {
        $trip = TransportTrip::where('school_id', currentSchoolId())->findOrFail($tripId);
        $tripStop = TripStop::where('transport_trip_id', $trip->id)->findOrFail($tripStopId);

        $validated = $request->validate([
            'sequence' => 'sometimes|required|integer|min:0',
            'arrived_at' => 'nullable|date',
            'departed_at' => 'nullable|date',
            'status' => 'nullable|in:pending,arrived,departed,cancelled',
        ]);

        $tripStop = $this->tripStopService->updateTripStop($tripStop, $validated);

        return response()->json(['success' => true, 'data' => $tripStop]);
    }

    public function deleteTripStop($tripId, $tripStopId): JsonResponse
    {
        $trip = TransportTrip::where('school_id', currentSchoolId())->findOrFail($tripId);
        $tripStop = TripStop::where('transport_trip_id', $trip->id)->findOrFail($tripStopId);

        $tripStop->delete();

        return response()->json(['success' => true, 'message' => 'Trip stop deleted.']);
    }

    /**
     * Parent view: Get student's current/upcoming transport trip
     */
    public function parentViewStudentTrip(Request $request, $studentId): JsonResponse
    {
        $user = auth()->user();
        $student = Student::where('school_id', currentSchoolId())->findOrFail($studentId);

        // Verify parent has access to this student (basic check)
        if ($user->id !== $student->parent_id && !$user->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have access to this student\'s transport information.',
            ], 403);
        }

        // Find the student's current or upcoming active trip
        $tripStudent = TripStudent::whereHas('trip', function ($q) {
            $q->where('school_id', currentSchoolId())
              ->whereIn('status', [
                    TransportTrip::STATUS_SCHEDULED,
                    TransportTrip::STATUS_READY,
                    TransportTrip::STATUS_IN_PROGRESS,
                ]);
        })
        ->where('student_id', $student->id)
        ->with([
            'trip.vehicle',
            'trip.driver',
            'trip.route',
            'trip.tripStops.stop',
        ])
        ->orderBy('created_at', 'desc')
        ->first();

        if (!$tripStudent) {
            return response()->json([
                'success' => true,
                'data' => null,
                'message' => 'Student has no active transport trips at the moment.',
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $tripStudent->id,
                'student' => ['id' => $student->id, 'name' => $student->name],
                'trip' => [
                    'id' => $tripStudent->trip->id,
                    'direction' => $tripStudent->trip->direction,
                    'status' => $tripStudent->trip->status,
                    'actual_start' => $tripStudent->trip->actual_start,
                    'actual_end' => $tripStudent->trip->actual_end,
                ],
                'vehicle' => [
                    'id' => $tripStudent->trip->vehicle->id ?? null,
                    'number' => $tripStudent->trip->vehicle->registration_number ?? null,
                    'last_lat' => $tripStudent->trip->vehicle->last_lat ?? null,
                    'last_lng' => $tripStudent->trip->vehicle->last_lng ?? null,
                    'last_speed' => $tripStudent->trip->vehicle->last_speed ?? null,
                ],
                'status' => $tripStudent->status,
                'boarding_stop' => $tripStudent->boarding_stop_id ? [
                    'id' => $tripStudent->boarding_stop_id,
                    'name' => $tripStudent->trip->tripStops->firstWhere('stop_id', $tripStudent->boarding_stop_id)?->stop->name ?? 'Unknown',
                    'time' => $tripStudent->boarding_time,
                ] : null,
                'dropoff_stop' => $tripStudent->dropoff_stop_id ? [
                    'id' => $tripStudent->dropoff_stop_id,
                    'name' => $tripStudent->trip->tripStops->firstWhere('stop_id', $tripStudent->dropoff_stop_id)?->stop->name ?? 'Unknown',
                    'time' => $tripStudent->dropoff_time,
                ] : null,
                'stops' => $tripStudent->trip->tripStops->map(function ($stop) {
                    return [
                        'id' => $stop->id,
                        'name' => $stop->stop->name ?? 'Unknown',
                        'sequence' => $stop->sequence,
                        'status' => $stop->status,
                        'arrived_at' => $stop->arrived_at,
                        'departed_at' => $stop->departed_at,
                    ];
                })->sortBy('sequence'),
            ],
        ]);
    }

    /**
     * Parent view: Get student's transport status summary
     */
    public function parentStudentTransportStatus(Request $request, $studentId): JsonResponse
    {
        $user = auth()->user();
        $student = Student::where('school_id', currentSchoolId())->findOrFail($studentId);

        if ($user->id !== $student->parent_id && !$user->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have access to this student\'s transport information.',
            ], 403);
        }

        $tripStudent = TripStudent::whereHas('trip', function ($q) {
            $q->where('school_id', currentSchoolId())
              ->whereIn('status', ['scheduled', 'started', 'ongoing']);
        })
        ->where('student_id', $student->id)
        ->with('trip.vehicle')
        ->orderBy('created_at', 'desc')
        ->first();

        if (!$tripStudent) {
            return response()->json([
                'success' => true,
                'data' => [
                    'has_active_trip' => false,
                    'status' => 'no_trip',
                    'message' => 'No active trip',
                ],
            ]);
        }

        $trip = $tripStudent->trip;
        $vehicle = $trip->route->vehicle;

        // Determine friendly status
        $status = match($tripStudent->status) {
            'pending' => 'Waiting to board',
            'boarded' => 'On the bus',
            TripStudent::STATUS_DROPPED_OFF => 'Dropped off',
            default => $tripStudent->status,
        };

        return response()->json([
            'success' => true,
            'data' => [
                'has_active_trip' => true,
                'student_status' => $tripStudent->status,
                'trip_status' => $trip->status,
                'status' => $status,
                'bus_number' => $vehicle->registration_number ?? 'Unknown',
                'vehicle_speed' => $vehicle->last_speed ?? 0,
                'has_gps' => $vehicle->last_lat !== null && $vehicle->last_lng !== null,
            ],
        ]);
    }
}

