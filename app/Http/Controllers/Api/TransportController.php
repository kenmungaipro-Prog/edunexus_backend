<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Vehicle, Driver, Student, TimetableSlot, ClassRoom, TransportRoute};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Events\VehicleLocationUpdated;
use Carbon\Carbon;

class TransportController extends Controller
{
    public function index(): JsonResponse
    {
        $routes = TransportRoute::where('school_id', currentSchoolId())
            ->with(['vehicle', 'driver', 'students'])
            ->withCount('students')
            ->get();

        return response()->json(['success' => true, 'data' => $routes]);
    }

    public function storeVehicle(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'registration_number' => 'required|string|unique:vehicles',
            'make' => 'nullable|string',
            'model' => 'nullable|string',
            'year' => 'nullable|integer',
            'capacity' => 'required|integer|min:1',
            'status' => 'required|in:active,maintenance,inactive',
        ]);

        $vehicle = Vehicle::create($validated);
        return response()->json(['success' => true, 'data' => $vehicle], 201);
    }

    public function vehicles(): JsonResponse
    {
        $vehicles = Vehicle::where('status', 'active')->get();
        return response()->json(['success' => true, 'data' => $vehicles]);
    }

    public function storeDriver(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'license_no' => 'required|string|unique:drivers',
            'license_expiry' => 'required|date',
            'status' => 'required|in:active,suspended,inactive',
        ]);

        $driver = Driver::create($validated);
        return response()->json(['success' => true, 'data' => $driver], 201);
    }

    public function drivers(): JsonResponse
    {
        $drivers = Driver::where('status', 'active')->get();
        return response()->json(['success' => true, 'data' => $drivers]);
    }

    // Restored to live() to match your API routes and fixed the array/closure syntax
    public function live(): JsonResponse
    {
        // Gather all vehicles to show complete real-time fleet overview
        $vehicles = Vehicle::where('status', 'active')
            ->with('currentRoute.driver')
            ->get();

        $formatted = $vehicles->map(function ($vehicle) {
            $assignedRoute = $vehicle->currentRoute;
            
            // Baseline Fallbacks centered on local Kenyan project coordinates if GPS telemetry fails
            return [
                'vehicle_id' => $vehicle->id,
                'number'     => $vehicle->registration_number,
                'route'      => $assignedRoute ? $assignedRoute->name : null,
                'driver'     => ($assignedRoute && $assignedRoute->driver) ? $assignedRoute->driver->name : null,
                'lat'        => $vehicle->last_lat ?? -1.2825, 
                'lng'        => $vehicle->last_lng ?? 36.8146,
                'speed'      => $vehicle->last_speed ?? 0,
                'updated_at' => $vehicle->location_updated_at 
                                    ? Carbon::parse($vehicle->location_updated_at)->toIso8601String() 
                                    : now()->toIso8601String(),
            ];
        });

        return response()->json(['success' => true, 'data' => $formatted]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'       => 'required|string|max:255',
            'vehicle_id' => 'required|exists:vehicles,id',
            'driver_id'  => 'required|exists:drivers,id',
            'stops'      => 'required|array|min:1',
            'stops.*.name'         => 'required|string',
            'stops.*.pickup_time'  => 'required|date_format:H:i',
            'stops.*.drop_time'    => 'required|date_format:H:i',
            'monthly_fee'          => 'required|numeric|min:0',
        ]);

        $validated['school_id'] = currentSchoolId();

        $route = TransportRoute::create($validated);
        return response()->json(['success' => true, 'data' => $route], 201);
    }

    public function assignStudent(Request $request, $id): JsonResponse
    {
        $transportRoute = TransportRoute::findOrFail($id);

        $validated = $request->validate([
            'student_id' => 'required|exists:students,id',
            'stop'       => 'required|string',
        ]);

        // Guard against duplicate route assignments to fulfill the unique table constraint
        $exists = DB::table('transport_assignments')
            ->where('student_id', $validated['student_id'])
            ->where('transport_route_id', $id)
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false, 
                'message' => 'This student is already allocated to this transit route module asset.'
            ], 422);
        }

        $transportRoute->students()->attach($validated['student_id'], [
            'stop' => $validated['stop']
        ]);

        return response()->json(['success' => true, 'message' => 'Student successfully assigned to route.']);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $transportRoute = TransportRoute::findOrFail($id);

        $validated = $request->validate([
            'name'       => 'sometimes|required|string|max:255',
            'vehicle_id' => 'sometimes|required|exists:vehicles,id',
            'driver_id'  => 'sometimes|required|exists:drivers,id',
            'stops'      => 'sometimes|required|array|min:1',
            'stops.*.name'         => 'required|string',
            'stops.*.pickup_time'  => 'required|date_format:H:i',
            'stops.*.drop_time'    => 'required|date_format:H:i',
            'monthly_fee'          => 'sometimes|required|numeric|min:0',
        ]);

        $transportRoute->update($validated);
        return response()->json(['success' => true, 'data' => $transportRoute->fresh()]);
    }

    public function destroy($id): JsonResponse
    {
        $transportRoute = TransportRoute::findOrFail($id);
        $transportRoute->delete();
        
        return response()->json(['success' => true, 'message' => 'Route deleted.']);
    }

    public function show($id): JsonResponse
    {
        $transportRoute = TransportRoute::with(['vehicle', 'driver', 'students.classRoom'])
            ->findOrFail($id);

        return response()->json(['success' => true, 'data' => $transportRoute]);
    }

    public function updateTelemetry(Request $request, $id): JsonResponse
    {
        $vehicle = \App\Models\Vehicle::findOrFail($id);

        $request->validate([
            'lat'   => 'required|numeric',
            'lng'   => 'required|numeric',
            'speed' => 'required|integer|min:0',
        ]);

        // Update the vehicle coordinates in the database
        $vehicle->update([
            'last_lat'            => $request->lat,
            'last_lng'            => $request->lng,
            'last_speed'          => $request->speed,
            'location_updated_at' => now(),
        ]);

        // Load relationships needed by your event payload formatting if necessary
        $vehicle->load(['currentRoute.driver']);

        // Broadcast the real-time event via Reverb/Pusher immediately
        broadcast(new VehicleLocationUpdated($vehicle));

        return response()->json([
            'success' => true,
            'message' => 'Telemetry updated and broadcast successfully.',
            'data'    => [
                'lat'   => $vehicle->last_lat,
                'lng'   => $vehicle->last_lng,
                'speed' => $vehicle->last_speed,
            ]
        ]);
    }
}