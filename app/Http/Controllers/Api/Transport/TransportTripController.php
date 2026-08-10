<?php

namespace App\Http\Controllers\Api\Transport;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\Transport\{TransportRoute, TransportTrip, TransportStop, TripStudent, TripStop};
use App\Services\Transport\{TripService, TripStopService, TripStudentService};
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

        try {
            $trip = $this->tripService->startTrip($trip);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $trip]);
    }

    public function endTrip(Request $request, $tripId): JsonResponse
    {
        $trip = $this->tripService->getTripForSchool(currentSchoolId(), $tripId);

        try {
            $trip = $this->tripService->completeTrip($trip);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $trip]);
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
}
