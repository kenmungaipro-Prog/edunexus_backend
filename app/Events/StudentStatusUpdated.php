<?php

namespace App\Events;

use App\Models\Student;
use App\Models\Transport\TransportTrip;
use App\Models\Transport\TripStudent;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StudentStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public array $student;

    public array $trip;

    public array $tripStudent;

    public string $action;

    public ?int $schoolId;

    public function __construct(
        Student $student,
        TransportTrip $trip,
        TripStudent $tripStudent,
        string $action = 'updated'
    ) {
        $this->schoolId = $student->school_id ?? $trip->school_id ?? null;

        $this->student = [
            'id' => $student->id,
            'name' => $student->name,
            'admission_number' => $student->admission_no ?? null,
        ];

        $this->trip = [
            'id' => $trip->id,
            'direction' => $trip->direction ?? null,
            'status' => $trip->status ?? null,
            'vehicle_id' => $trip->vehicle_id ?? null,
            'driver_id' => $trip->driver_id ?? null,
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

        $this->tripStudent = [
            'id' => $tripStudent->id,
            'status' => $tripStudent->status ?? TripStudent::STATUS_PENDING,
            'boarding_stop_id' => $tripStudent->boarding_stop_id ?? null,
            'boarding_time' => $tripStudent->boarding_time
                ? $tripStudent->boarding_time->toIso8601String()
                : null,
            'dropoff_stop_id' => $tripStudent->dropoff_stop_id ?? null,
            'dropoff_time' => $tripStudent->dropoff_time
                ? $tripStudent->dropoff_time->toIso8601String()
                : null,
        ];

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
        return 'student.status.updated';
    }
}