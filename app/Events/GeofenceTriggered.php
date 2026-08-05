<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
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

    public function broadcastOn(): Channel
    {
        $channel = $this->schoolId ? "fleet-geofences.{$this->schoolId}" : 'fleet-geofences';
        return new Channel($channel);
    }

    public function broadcastAs(): string
    {
        return 'vehicle.geofence.triggered';
    }
}
