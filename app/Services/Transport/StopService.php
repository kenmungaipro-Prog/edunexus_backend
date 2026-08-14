<?php

namespace App\Services\Transport;

use App\Models\Transport\TransportRoute;
use App\Models\Transport\TransportStop;
use Illuminate\Support\Collection;

class StopService
{
    public function createStop(TransportRoute $route, array $data): TransportStop
    {
        $validated = $this->normalizeStopPayload($data);
        $this->validateSequence($route, $validated['sequence']);

        return $route->stops()->create($validated);
    }

    public function updateStop(TransportRoute $route, TransportStop $stop, array $data): TransportStop
    {
        $validated = $this->normalizeStopPayload($data);

        if (array_key_exists('sequence', $validated)) {
            $this->validateSequence($route, $validated['sequence'], $stop->id);
        }

        $stop->update($validated);

        return $stop;
    }

    public function deleteStop(TransportStop $stop): void
    {
        $stop->delete();
    }

    public function listStops(TransportRoute $route): Collection
    {
        return $route->stops()->orderBy('sequence')->get();
    }

    protected function validateSequence(TransportRoute $route, int $sequence, ?int $ignoreStopId = null): void
    {
        $query = $route->stops()->where('sequence', $sequence);

        if ($ignoreStopId) {
            $query->where('id', '!=', $ignoreStopId);
        }

        if ($query->exists()) {
            throw new \InvalidArgumentException('A stop with this sequence already exists for the route.');
        }
    }

    private function normalizeStopPayload(array $data): array
    {
        return [
            'name' => trim($data['name'] ?? ''),
            'latitude' => (float) ($data['latitude'] ?? 0),
            'longitude' => (float) ($data['longitude'] ?? 0),
            'sequence' => isset($data['sequence']) ? (int) $data['sequence'] : 0,
            'pickup_time' => $data['pickup_time'] ?? null,
            'dropoff_time' => $data['dropoff_time'] ?? null,
            'geofence_radius' => isset($data['geofence_radius']) ? (int) $data['geofence_radius'] : 120,
            'status' => $data['status'] ?? 'active',
        ];
    }
}
