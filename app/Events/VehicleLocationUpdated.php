<?php

namespace App\Events;

use App\Models\Vehicle;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class VehicleLocationUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public array $vehicle;

    public ?int $schoolId;

    public function __construct(Vehicle $vehicle)
    {
        $this->schoolId = $vehicle->currentRoute?->school_id;

        // Format the data to match the frontend LiveVehicle interface exactly
        $this->vehicle = [
            'vehicle_id' => $vehicle->id,
            'number'     => $vehicle->registration_number,
            'route'      => $vehicle->currentRoute ? $vehicle->currentRoute->name : null,
            'driver'     => $vehicle->currentRoute && $vehicle->currentRoute->driver ? $vehicle->currentRoute->driver->name : null,
            'lat'        => $vehicle->last_lat,
            'lng'        => $vehicle->last_lng,
            'speed'      => $vehicle->last_speed,
            'updated_at' => $vehicle->location_updated_at ? $vehicle->location_updated_at->toIso8601String() : null,
        ];
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): Channel
    {
        $channel = $this->schoolId ? "fleet-delivery.{$this->schoolId}" : 'fleet-delivery';

        return new Channel($channel);
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'vehicle.location.updated';
    }
}