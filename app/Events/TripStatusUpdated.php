<?php

namespace App\Events;

use App\Models\Transport\TransportTrip;
use App\Models\Transport\TripStop;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TripStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public array $trip;

    public ?array $stop = null;

    public string $action;

    public ?int $schoolId;

    public function __construct(
        TransportTrip $trip,
        string $action = 'updated',
        ?TripStop $stop = null
    ) {
        $this->schoolId = $trip->school_id ?? null;

        $this->trip = [
            'id' => $trip->id,
            'status' => $trip->status ?? null,
            'direction' => $trip->direction ?? null,
            'driver_id' => $trip->driver_id ?? null,
            'vehicle_id' => $trip->vehicle_id ?? null,
            'route_id' => $trip->transport_route_id ?? null,
            'scheduled_start' => $trip->scheduled_start
                ? $trip->scheduled_start->toIso8601String()
                : null,
            'actual_start' => $trip->actual_start
                ? $trip->actual_start->toIso8601String()
                : null,
            'actual_end' => $trip->actual_end
                ? $trip->actual_end->toIso8601String()
                : null,
        ];

        if ($stop) {
            $this->stop = [
                'id' => $stop->id,
                'transport_stop_id' => $stop->transport_stop_id ?? null,
                'sequence' => $stop->sequence ?? null,
                'name' => $stop->name ?? null,
                'latitude' => $stop->latitude ?? null,
                'longitude' => $stop->longitude ?? null,
                'arrived_at' => $stop->arrived_at
                    ? $stop->arrived_at->toIso8601String()
                    : null,
                'departed_at' => $stop->departed_at
                    ? $stop->departed_at->toIso8601String()
                    : null,
                'status' => $stop->status ?? null,
            ];
        }

        $this->action = $action;
    }

    public function broadcastOn(): PrivateChannel
    {
        $channel = $this->schoolId
            ? "fleet-delivery.{$this->schoolId}"
            : 'fleet-delivery';

        return new PrivateChannel($channel);
    }

    public function broadcastAs(): string
    {
        return 'trip.status.updated';
    }
}