<?php

namespace App\Services\Transport;

use App\Models\Driver;
use App\Models\Vehicle;
use App\Models\Transport\VehicleDriverAssignment;
use Carbon\Carbon;

class VehicleDriverAssignmentService
{
    public function assignDriverToVehicle(Vehicle $vehicle, Driver $driver, array $data): VehicleDriverAssignment
    {
        if ($vehicle->school_id !== $driver->school_id) {
            throw new \InvalidArgumentException('Vehicle and driver must belong to the same school.');
        }

        if ($this->activeAssignmentForVehicle($vehicle)) {
            throw new \InvalidArgumentException('This vehicle already has an active driver assignment.');
        }

        if ($this->activeAssignmentForDriver($driver)) {
            throw new \InvalidArgumentException('This driver already has an active vehicle assignment.');
        }

        return VehicleDriverAssignment::create([
            'school_id' => $vehicle->school_id,
            'vehicle_id' => $vehicle->id,
            'driver_id' => $driver->id,
            'started_at' => $data['started_at'] ?? Carbon::now(),
            'status' => $data['status'] ?? 'active',
            'notes' => $data['notes'] ?? null,
        ]);
    }

    public function updateAssignment(VehicleDriverAssignment $assignment, array $data): VehicleDriverAssignment
    {
        if (isset($data['vehicle_id']) && $data['vehicle_id'] !== $assignment->vehicle_id) {
            throw new \InvalidArgumentException('Vehicle cannot be changed on an existing assignment.');
        }

        if (isset($data['driver_id']) && $data['driver_id'] !== $assignment->driver_id) {
            throw new \InvalidArgumentException('Driver cannot be changed on an existing assignment.');
        }

        $payload = [];

        if (array_key_exists('started_at', $data)) {
            $payload['started_at'] = $data['started_at'];
        }

        if (array_key_exists('ended_at', $data)) {
            $payload['ended_at'] = $data['ended_at'];
            $payload['status'] = $data['status'] ?? 'ended';
        }

        if (array_key_exists('status', $data)) {
            $payload['status'] = $data['status'];
        }

        if (array_key_exists('notes', $data)) {
            $payload['notes'] = $data['notes'];
        }

        $assignment->update($payload);

        return $assignment;
    }

    public function endAssignment(VehicleDriverAssignment $assignment): VehicleDriverAssignment
    {
        if ($assignment->ended_at !== null) {
            return $assignment;
        }

        $assignment->update([
            'ended_at' => Carbon::now(),
            'status' => 'ended',
        ]);

        return $assignment;
    }

    public function activeAssignmentForVehicle(Vehicle $vehicle): ?VehicleDriverAssignment
    {
        return VehicleDriverAssignment::where('vehicle_id', $vehicle->id)
            ->where('school_id', $vehicle->school_id)
            ->where('status', 'active')
            ->whereNull('ended_at')
            ->first();
    }

    public function activeAssignmentForDriver(Driver $driver): ?VehicleDriverAssignment
    {
        return VehicleDriverAssignment::where('driver_id', $driver->id)
            ->where('school_id', $driver->school_id)
            ->where('status', 'active')
            ->whereNull('ended_at')
            ->first();
    }
}
