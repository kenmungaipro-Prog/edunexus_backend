<?php

namespace App\Services\Transport;

use App\Models\Transport\TransportTrip;
use App\Models\Transport\TransportStop;
use App\Models\Transport\TripStop;
use Illuminate\Database\Eloquent\Collection;

class TripStopService
{
    public function createTripStop(TransportTrip $trip, TransportStop $stop, array $data): TripStop
    {
        $payload = $this->normalizeTripStopPayload($stop, $data);
        $this->validateSequence($trip, $payload['sequence']);

        return $trip->tripStops()->create($payload);
    }

    public function updateTripStop(TripStop $tripStop, array $data): TripStop
    {
        $payload = $this->normalizeTripStopUpdatePayload($data);

        if (array_key_exists('sequence', $payload)) {
            $this->validateSequence($tripStop->trip, $payload['sequence'], $tripStop->id);
        }

        $tripStop->update($payload);

        return $tripStop;
    }

    public function listTripStops(TransportTrip $trip): Collection
    {
        return $trip->tripStops()->orderBy('sequence')->get();
    }

    protected function validateSequence(TransportTrip $trip, int $sequence, ?int $ignoreId = null): void
    {
        $query = $trip->tripStops()->where('sequence', $sequence);

        if ($ignoreId) {
            $query->where('id', '!=', $ignoreId);
        }

        if ($query->exists()) {
            throw new \InvalidArgumentException('A trip stop with this sequence already exists for the trip.');
        }
    }

    protected function normalizeTripStopPayload(TransportStop $stop, array $data): array
    {
        return [
            'transport_stop_id' => $stop->id,
            'sequence' => $data['sequence'] ?? $stop->sequence,
            'name' => $stop->name,
            'latitude' => $stop->latitude,
            'longitude' => $stop->longitude,
            'pickup_time' => $stop->pickup_time,
            'dropoff_time' => $stop->dropoff_time,
            'arrived_at' => $data['arrived_at'] ?? null,
            'departed_at' => $data['departed_at'] ?? null,
            'status' => $data['status'] ?? 'pending',
        ];
    }

    protected function normalizeTripStopUpdatePayload(array $data): array
    {
        $payload = [];

        if (array_key_exists('sequence', $data)) {
            $payload['sequence'] = (int) $data['sequence'];
        }

        if (array_key_exists('arrived_at', $data)) {
            $payload['arrived_at'] = $data['arrived_at'];
            $payload['status'] = $payload['status'] ?? 'arrived';
        }

        if (array_key_exists('departed_at', $data)) {
            $payload['departed_at'] = $data['departed_at'];
            $payload['status'] = $payload['status'] ?? 'departed';
        }

        if (array_key_exists('status', $data)) {
            $payload['status'] = $data['status'];
        }

        return $payload;
    }
}
