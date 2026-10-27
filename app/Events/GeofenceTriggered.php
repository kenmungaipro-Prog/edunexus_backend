<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GeofenceTriggered implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public array $payload;
    public ?int $schoolId;

    public function __construct(array $payload, ?int $schoolId = null)
    {
        $this->payload = $payload;
        $this->schoolId = $schoolId;
    }

    public function broadcastOn(): array|PrivateChannel
    {
        return $this->schoolId
            ? new PrivateChannel("fleet-geofences.{$this->schoolId}")
            : [];
    }

    public function broadcastAs(): string
    {
        return 'vehicle.geofence.triggered';
    }
}
