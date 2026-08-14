<?php

namespace App\Services\Transport;

use App\Models\Transport\TransportRoute;
use App\Models\Transport\TransportTrip;
use Illuminate\Database\Eloquent\Collection;

class TripService
{
    public function scheduleTrip(TransportRoute $route, array $data): TransportTrip
    {
        return TransportTrip::create([
            'school_id' => $route->school_id,
            'transport_route_id' => $route->id,
            'vehicle_id' => $route->vehicle_id,
            'driver_id' => $route->driver_id,
            'direction' => $data['direction'],
            'scheduled_start' => $data['scheduled_start'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => TransportTrip::STATUS_SCHEDULED,
        ]);
    }

    public function startTrip(TransportTrip $trip): TransportTrip
    {
        if (! in_array($trip->status, [TransportTrip::STATUS_SCHEDULED, TransportTrip::STATUS_READY], true)) {
            throw new \InvalidArgumentException('Only scheduled or ready trips may be started.');
        }

        $timestamp = now();

        $trip->update([
            'actual_start' => $timestamp,
            'start_time' => $timestamp,
            'status' => TransportTrip::STATUS_IN_PROGRESS,
        ]);

        return $trip->fresh();
    }

    public function completeTrip(TransportTrip $trip): TransportTrip
    {
        if ($trip->status !== TransportTrip::STATUS_IN_PROGRESS) {
            throw new \InvalidArgumentException('Only in-progress trips can be completed.');
        }

        $timestamp = now();

        $trip->update([
            'actual_end' => $timestamp,
            'end_time' => $timestamp,
            'status' => TransportTrip::STATUS_COMPLETED,
        ]);

        return $trip->fresh();
    }

    public function getTripForSchool(int $schoolId, int $tripId, array $relations = []): TransportTrip
    {
        return TransportTrip::with($relations)
            ->where('school_id', $schoolId)
            ->findOrFail($tripId);
    }

    public function listTrips(int $schoolId): Collection
    {
        return TransportTrip::with(['route.vehicle', 'route.driver'])
            ->where('school_id', $schoolId)
            ->orderByDesc('scheduled_start')
            ->get();
    }

    public function listTripsForDriver(int $schoolId, int $driverId): Collection
    {
        return TransportTrip::with(['route.vehicle'])
            ->where('school_id', $schoolId)
            ->where('driver_id', $driverId)
            ->orderByDesc('start_time')
            ->get();
    }
}
