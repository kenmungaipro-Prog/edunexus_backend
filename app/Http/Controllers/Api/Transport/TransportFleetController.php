<?php

namespace App\Http\Controllers\Api\Transport;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\Vehicle;
use App\Models\Transport\VehicleDriverAssignment;
use App\Services\Transport\VehicleDriverAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TransportFleetController extends Controller
{
    protected VehicleDriverAssignmentService $assignmentService;

    public function __construct(VehicleDriverAssignmentService $assignmentService)
    {
        $this->assignmentService = $assignmentService;
    }

    public function vehicles(): JsonResponse
    {
        $vehicles = Vehicle::where('school_id', currentSchoolId())
            ->with('activeDriverAssignment.driver')
            ->orderBy('registration_number')
            ->get();

        return response()->json(['success' => true, 'data' => $vehicles]);
    }

    public function storeVehicle(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'registration_number' => 'required|string|unique:vehicles',
            'make' => 'nullable|string',
            'model' => 'nullable|string',
            'capacity' => 'required|integer|min:1',
            'status' => 'required|in:active,inactive',
        ]);

        $vehicle = Vehicle::create(array_merge($validated, [
            'school_id' => currentSchoolId(),
        ]));

        return response()->json(['success' => true, 'data' => $vehicle], 201);
    }

    public function showVehicle($id): JsonResponse
    {
        $vehicle = Vehicle::where('school_id', currentSchoolId())
            ->with(['activeDriverAssignment.driver'])
            ->findOrFail($id);

        return response()->json(['success' => true, 'data' => $vehicle]);
    }

    public function updateVehicle(Request $request, $id): JsonResponse
    {
        $vehicle = Vehicle::where('school_id', currentSchoolId())->findOrFail($id);

        $validated = $request->validate([
            'registration_number' => "sometimes|required|string|unique:vehicles,registration_number,{$vehicle->id}",
            'make' => 'nullable|string',
            'model' => 'nullable|string',
            'capacity' => 'sometimes|required|integer|min:1',
            'status' => 'sometimes|required|in:active,inactive',
        ]);

        $vehicle->update($validated);

        return response()->json(['success' => true, 'data' => $vehicle->fresh()]);
    }

    public function deleteVehicle($id): JsonResponse
    {
        $vehicle = Vehicle::where('school_id', currentSchoolId())->findOrFail($id);
        $vehicle->delete();

        return response()->json(['success' => true, 'message' => 'Vehicle deleted.']);
    }

    public function drivers(): JsonResponse
    {
        $drivers = Driver::where('school_id', currentSchoolId())
            ->with('activeVehicleAssignment.vehicle')
            ->orderBy('name')
            ->get();

        return response()->json(['success' => true, 'data' => $drivers]);
    }

    public function storeDriver(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'license_no' => 'required|string|unique:drivers',
            'license_expiry' => 'required|date',
            'status' => 'required|in:active,inactive',
        ]);

        $driver = Driver::create(array_merge($validated, [
            'school_id' => currentSchoolId(),
        ]));

        return response()->json(['success' => true, 'data' => $driver], 201);
    }

    public function showDriver($id): JsonResponse
    {
        $driver = Driver::where('school_id', currentSchoolId())
            ->with(['activeVehicleAssignment.vehicle'])
            ->findOrFail($id);

        return response()->json(['success' => true, 'data' => $driver]);
    }

    public function updateDriver(Request $request, $id): JsonResponse
    {
        $driver = Driver::where('school_id', currentSchoolId())->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'phone' => 'sometimes|required|string|max:20',
            'license_no' => "sometimes|required|string|unique:drivers,license_no,{$driver->id}",
            'license_expiry' => 'sometimes|required|date',
            'status' => 'sometimes|required|in:active,inactive',
        ]);

        $driver->update($validated);

        return response()->json(['success' => true, 'data' => $driver->fresh()]);
    }

    public function deleteDriver($id): JsonResponse
    {
        $driver = Driver::where('school_id', currentSchoolId())->findOrFail($id);
        $driver->delete();

        return response()->json(['success' => true, 'message' => 'Driver deleted.']);
    }

    public function vehicleAssignments($vehicleId): JsonResponse
    {
        $vehicle = Vehicle::where('school_id', currentSchoolId())
            ->findOrFail($vehicleId);

        return response()->json(['success' => true, 'data' => $vehicle->driverAssignments()->with('driver')->orderByDesc('started_at')->get()]);
    }

    public function storeVehicleAssignment(Request $request, $vehicleId): JsonResponse
    {
        $vehicle = Vehicle::where('school_id', currentSchoolId())->findOrFail($vehicleId);

        $validated = $request->validate([
            'driver_id' => [
                'required',
                'integer',
                Rule::exists('drivers', 'id')->where(fn ($query) => $query->where('school_id', currentSchoolId())),
            ],
            'started_at' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
        ]);

        $driver = Driver::where('school_id', currentSchoolId())->findOrFail($validated['driver_id']);

        try {
            $assignment = $this->assignmentService->assignDriverToVehicle($vehicle, $driver, [
                'started_at' => $validated['started_at'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $assignment], 201);
    }

    public function updateVehicleAssignment(Request $request, $vehicleId, $assignmentId): JsonResponse
    {
        $vehicle = Vehicle::where('school_id', currentSchoolId())->findOrFail($vehicleId);
        $assignment = VehicleDriverAssignment::where('vehicle_id', $vehicle->id)
            ->where('school_id', currentSchoolId())
            ->findOrFail($assignmentId);

        $validated = $request->validate([
            'started_at' => 'nullable|date',
            'ended_at' => 'nullable|date',
            'status' => 'nullable|in:active,ended,inactive',
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            $updated = $this->assignmentService->updateAssignment($assignment, $validated);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $updated]);
    }

    public function deleteVehicleAssignment($vehicleId, $assignmentId): JsonResponse
    {
        $vehicle = Vehicle::where('school_id', currentSchoolId())->findOrFail($vehicleId);
        $assignment = VehicleDriverAssignment::where('vehicle_id', $vehicle->id)
            ->where('school_id', currentSchoolId())
            ->findOrFail($assignmentId);

        $assignment = $this->assignmentService->endAssignment($assignment);

        return response()->json(['success' => true, 'data' => $assignment]);
    }

    public function driverAssignments($driverId): JsonResponse
    {
        $driver = Driver::where('school_id', currentSchoolId())
            ->findOrFail($driverId);

        return response()->json(['success' => true, 'data' => $driver->vehicleAssignments()->with('vehicle')->orderByDesc('started_at')->get()]);
    }
}
