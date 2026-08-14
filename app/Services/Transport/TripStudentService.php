<?php

namespace App\Services\Transport;

use App\Models\Student;
use App\Models\Transport\TripStudent;
use App\Models\Transport\TransportAssignment;
use App\Models\Transport\TransportStop;
use App\Models\Transport\TransportTrip;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

class TripStudentService
{
    public function createTripStudent(TransportTrip $trip, Student $student, array $data): TripStudent
    {
        $this->ensureTripAcceptsStudents($trip);
        $this->ensureStudentMatchesSchool($trip, $student);
        $this->ensureStudentHasAssignment($trip, $student);

        if ($trip->tripStudents()->where('student_id', $student->id)->exists()) {
            throw new \InvalidArgumentException('A boarding record already exists for this student on this trip.');
        }

        $payload = $this->normalizePayload($trip, $student, $data);

        return $trip->tripStudents()->create($payload);
    }

    public function updateTripStudent(TripStudent $tripStudent, array $data): TripStudent
    {
        if (array_key_exists('status', $data) && in_array($data['status'], [TripStudent::STATUS_BOARDED, TripStudent::STATUS_DROPPED_OFF], true)) {
            throw new \InvalidArgumentException('Use the board or drop-off endpoints to change lifecycle state.');
        }

        if (array_key_exists('boarding_time', $data) || array_key_exists('boarding_stop_id', $data) || array_key_exists('dropoff_time', $data) || array_key_exists('dropoff_stop_id', $data)) {
            throw new \InvalidArgumentException('Use the board or drop-off endpoints to update boarding and drop-off details.');
        }

        $this->ensureTripAcceptsStudents($tripStudent->trip);
        $this->ensureStudentMatchesSchool($tripStudent->trip, $tripStudent->student);

        $payload = [
            'status' => $data['status'] ?? $tripStudent->status,
        ];

        $tripStudent->update($payload);

        return $tripStudent->fresh();
    }

    public function boardStudent(TripStudent $tripStudent, array $data): TripStudent
    {
        $trip = $tripStudent->trip;
        $this->ensureTripAcceptsStudents($trip);

        if ($tripStudent->status !== TripStudent::STATUS_PENDING) {
            throw new \InvalidArgumentException('Only expected students may be boarded.');
        }

        if (isset($tripStudent->dropoff_time) && $tripStudent->dropoff_time !== null) {
            throw new \InvalidArgumentException('Boarding cannot occur after drop-off.');
        }

        $payload = [
            'boarding_stop_id' => $data['boarding_stop_id'] ?? null,
            'boarding_time' => $data['boarding_time'] ?? null,
            'status' => TripStudent::STATUS_BOARDED,
        ];

        $this->validateStops($trip, $payload['boarding_stop_id'], null);
        $this->validateBoardingPayload($payload);

        $tripStudent->update($payload);

        return $tripStudent->fresh();
    }

    public function dropOffStudent(TripStudent $tripStudent, array $data): TripStudent
    {
        $trip = $tripStudent->trip;
        $this->ensureTripAcceptsStudents($trip);

        if ($tripStudent->status !== TripStudent::STATUS_BOARDED) {
            throw new \InvalidArgumentException('Only boarded students may be dropped off.');
        }

        $payload = [
            'dropoff_stop_id' => $data['dropoff_stop_id'] ?? null,
            'dropoff_time' => $data['dropoff_time'] ?? null,
            'status' => TripStudent::STATUS_DROPPED_OFF,
        ];

        $this->validateStops($trip, null, $payload['dropoff_stop_id']);
        $this->validateDropoffPayload($tripStudent, $payload);

        $tripStudent->update($payload);

        return $tripStudent->fresh();
    }

    public function listTripStudents(TransportTrip $trip): Collection
    {
        return $trip->tripStudents()->with('student', 'boardingStop', 'dropoffStop')->get();
    }

    protected function normalizePayload(TransportTrip $trip, Student $student, array $data, ?TripStudent $existing = null): array
    {
        $payload = [
            'school_id' => $trip->school_id,
            'transport_trip_id' => $trip->id,
            'student_id' => $student->id,
            'boarding_stop_id' => $data['boarding_stop_id'] ?? ($existing?->boarding_stop_id ?? null),
            'boarding_time' => $data['boarding_time'] ?? ($existing?->boarding_time ?? null),
            'dropoff_stop_id' => $data['dropoff_stop_id'] ?? ($existing?->dropoff_stop_id ?? null),
            'dropoff_time' => $data['dropoff_time'] ?? ($existing?->dropoff_time ?? null),
            'status' => $data['status'] ?? ($existing?->status ?? TripStudent::STATUS_PENDING),
        ];

        if ($existing === null) {
            $this->validateCreateStatus($payload['status']);
        }

        if ($payload['boarding_stop_id'] || $payload['dropoff_stop_id']) {
            $this->validateStops($trip, $payload['boarding_stop_id'], $payload['dropoff_stop_id']);
        }

        $this->validateStatus($payload);

        return $payload;
    }

    protected function ensureTripAcceptsStudents(TransportTrip $trip): void
    {
        if ($trip->status === TransportTrip::STATUS_COMPLETED) {
            throw new \InvalidArgumentException('Completed trips cannot accept trip student changes.');
        }
    }

    protected function ensureStudentMatchesSchool(TransportTrip $trip, Student $student): void
    {
        if ($student->school_id !== $trip->school_id) {
            throw new \InvalidArgumentException('The student must belong to the same school as the trip.');
        }
    }

    protected function ensureStudentHasAssignment(TransportTrip $trip, Student $student): void
    {
        if (! TransportAssignment::where('transport_route_id', $trip->transport_route_id)
            ->where('student_id', $student->id)
            ->exists()) {
            throw new \InvalidArgumentException('The student does not have a valid transport assignment for this route.');
        }
    }

    protected function validateStops(TransportTrip $trip, ?int $boardingStopId, ?int $dropoffStopId): void
    {
        foreach (['boarding_stop_id' => $boardingStopId, 'dropoff_stop_id' => $dropoffStopId] as $field => $stopId) {
            if (! $stopId) {
                continue;
            }

            if (! TransportStop::where('transport_route_id', $trip->transport_route_id)->where('id', $stopId)->exists()) {
                throw new \InvalidArgumentException(sprintf('The %s must belong to the trip route.', $field));
            }
        }
    }

    protected function validateCreateStatus(string $status): void
    {
        if (! in_array($status, [TripStudent::STATUS_PENDING, TripStudent::STATUS_CANCELLED], true)) {
            throw new \InvalidArgumentException('Trip student creation can only be pending or cancelled.');
        }
    }

    protected function validateStatus(array $payload): void
    {
        $allowed = [
            TripStudent::STATUS_PENDING,
            TripStudent::STATUS_BOARDED,
            TripStudent::STATUS_DROPPED_OFF,
            TripStudent::STATUS_CANCELLED,
        ];

        if (! in_array($payload['status'], $allowed, true)) {
            throw new \InvalidArgumentException('Invalid trip student status provided.');
        }

        if ($payload['status'] === TripStudent::STATUS_BOARDED && empty($payload['boarding_stop_id'])) {
            throw new \InvalidArgumentException('Boarding stop is required when marking a student as boarded.');
        }

        if ($payload['status'] === TripStudent::STATUS_BOARDED && empty($payload['boarding_time'])) {
            throw new \InvalidArgumentException('Boarding time is required when marking a student as boarded.');
        }

        if ($payload['status'] === TripStudent::STATUS_DROPPED_OFF) {
            if (empty($payload['boarding_stop_id']) || empty($payload['boarding_time'])) {
                throw new \InvalidArgumentException('Boarding details are required before a drop-off can be recorded.');
            }

            if (empty($payload['dropoff_stop_id'])) {
                throw new \InvalidArgumentException('Dropoff stop is required when marking a student as dropped off.');
            }

            if (empty($payload['dropoff_time'])) {
                throw new \InvalidArgumentException('Dropoff time is required when marking a student as dropped off.');
            }

            if (Carbon::parse($payload['dropoff_time'])->lessThan(Carbon::parse($payload['boarding_time']))) {
                throw new \InvalidArgumentException('Drop-off cannot occur before boarding.');
            }
        }
    }

    protected function validateBoardingPayload(array $payload): void
    {
        if (empty($payload['boarding_stop_id'])) {
            throw new \InvalidArgumentException('Boarding stop is required.');
        }

        if (empty($payload['boarding_time'])) {
            throw new \InvalidArgumentException('Boarding time is required.');
        }
    }

    protected function validateDropoffPayload(TripStudent $tripStudent, array $payload): void
    {
        if (empty($payload['dropoff_stop_id'])) {
            throw new \InvalidArgumentException('Dropoff stop is required.');
        }

        if (empty($payload['dropoff_time'])) {
            throw new \InvalidArgumentException('Dropoff time is required.');
        }

        if (Carbon::parse($payload['dropoff_time'])->lessThan(Carbon::parse($tripStudent->boarding_time))) {
            throw new \InvalidArgumentException('Drop-off cannot occur before boarding.');
        }
    }
}
