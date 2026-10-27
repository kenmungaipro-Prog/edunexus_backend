<?php

namespace App\Http\Controllers\Api\Transport;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Transport\TransportDemoSession;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TransportDemoController extends Controller
{
    private const POSITION_LEASE_SECONDS = 30;

    private const SESSION_LIMIT_MINUTES = 15;

    public function target(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId($request);
        $vehicleId = filter_var(config('transport.demo_vehicle_id'), FILTER_VALIDATE_INT);

        if ($vehicleId === false || $vehicleId < 1) {
            return response()->json([
                'success' => false,
                'message' => 'The approved demonstration vehicle is not configured.',
            ], 503);
        }

        $vehicle = Vehicle::query()
            ->where('school_id', $schoolId)
            ->where('status', 'active')
            ->whereHas('currentRoute', fn ($query) => $query->where('school_id', $schoolId))
            ->find($vehicleId);

        if (! $vehicle) {
            return response()->json([
                'success' => false,
                'message' => 'The configured demonstration vehicle is not available in this school.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'vehicle_id' => $vehicle->id,
                'number' => $vehicle->registration_number,
            ],
        ]);
    }

    public function active(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId($request);
        $session = $this->activeSession($schoolId);

        return response()->json([
            'success' => true,
            'data' => $session ? $this->formatSession($session, true) : null,
        ]);
    }

    public function start(Request $request, int $vehicleId): JsonResponse
    {
        $schoolId = $this->schoolId($request);
        $configuredVehicleId = filter_var(config('transport.demo_vehicle_id'), FILTER_VALIDATE_INT);
        abort_unless(
            $configuredVehicleId !== false && $configuredVehicleId === $vehicleId,
            404,
        );
        $vehicle = Vehicle::query()
            ->where('school_id', $schoolId)
            ->where('status', 'active')
            ->whereHas('currentRoute', fn ($query) => $query->where('school_id', $schoolId))
            ->findOrFail($vehicleId);

        $session = DB::transaction(function () use ($request, $schoolId, $vehicle) {
            School::query()->whereKey($schoolId)->lockForUpdate()->firstOrFail();

            $existing = TransportDemoSession::query()
                ->where('school_id', $schoolId)
                ->lockForUpdate()
                ->first();

            if ($existing && $this->isActive($existing)) {
                abort(response()->json([
                    'success' => false,
                    'message' => 'A demo session is already active for this school.',
                ], 409));
            }

            $existing?->delete();

            $now = now();

            return TransportDemoSession::create([
                'session_id' => (string) Str::uuid(),
                'school_id' => $schoolId,
                'vehicle_id' => $vehicle->id,
                'started_by' => $request->user()->id,
                'started_at' => $now,
                'expires_at' => $now->copy()->addSeconds(self::POSITION_LEASE_SECONDS),
                'hard_expires_at' => $now->copy()->addMinutes(self::SESSION_LIMIT_MINUTES),
            ]);
        });

        return response()->json([
            'success' => true,
            'data' => $this->formatSession($session, true),
        ], 201);
    }

    public function updatePosition(Request $request, int $vehicleId): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['required', 'uuid'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'speed' => ['nullable', 'numeric', 'min:0', 'max:400'],
            'heading' => ['nullable', 'numeric', 'between:0,360'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'timestamp' => ['required', 'date'],
        ]);
        $schoolId = $this->schoolId($request);
        $result = DB::transaction(function () use ($schoolId, $vehicleId, $validated) {
            $session = TransportDemoSession::query()
                ->where('school_id', $schoolId)
                ->where('vehicle_id', $vehicleId)
                ->where('session_id', $validated['session_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->isActive($session)) {
                $session->delete();

                return null;
            }

            $positionAt = Carbon::parse($validated['timestamp']);
            $now = now();
            if (
                $positionAt->lt($now->copy()->subSeconds(self::POSITION_LEASE_SECONDS))
                || $positionAt->gt($now->copy()->addSeconds(5))
                || ($session->position_at && $positionAt->lte($session->position_at))
            ) {
                throw ValidationException::withMessages([
                    'timestamp' => 'The GPS fix is stale, out of order, or too far in the future.',
                ]);
            }

            $session->update([
                'lat' => $validated['lat'],
                'lng' => $validated['lng'],
                'speed' => isset($validated['speed']) ? (int) round($validated['speed']) : null,
                'heading' => $validated['heading'] ?? null,
                'accuracy' => $validated['accuracy'] ?? null,
                'position_at' => $positionAt,
                'expires_at' => min(
                    $now->copy()->addSeconds(self::POSITION_LEASE_SECONDS),
                    $session->hard_expires_at
                ),
            ]);

            return $session->fresh();
        });

        if (! $result) {
            return response()->json([
                'success' => false,
                'message' => 'The demo session has expired. Start a new session to continue.',
            ], 410);
        }

        return response()->json(['success' => true, 'data' => $this->formatSession($result, false)]);
    }

    public function stop(Request $request, int $vehicleId): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['required', 'uuid'],
        ]);
        $session = TransportDemoSession::query()
            ->where('school_id', $this->schoolId($request))
            ->where('vehicle_id', $vehicleId)
            ->where('session_id', $validated['session_id'])
            ->first();

        $session?->delete();

        return response()->json(['success' => true, 'data' => null]);
    }

    private function schoolId(Request $request): int
    {
        $schoolId = $request->user()->school_id;
        abort_unless($schoolId, 403, 'A school administrator is required.');

        return (int) $schoolId;
    }

    private function activeSession(int $schoolId): ?TransportDemoSession
    {
        $session = TransportDemoSession::query()->where('school_id', $schoolId)->first();
        if ($session && ! $this->isActive($session)) {
            $session->delete();

            return null;
        }

        return $session;
    }

    private function isActive(TransportDemoSession $session): bool
    {
        $now = now();

        return $session->expires_at->gt($now) && $session->hard_expires_at->gt($now);
    }

    private function formatSession(TransportDemoSession $session, bool $includeSessionId): array
    {
        $data = [
            'vehicle_id' => $session->vehicle_id,
            'lat' => $session->lat,
            'lng' => $session->lng,
            'speed' => $session->speed,
            'heading' => $session->heading,
            'accuracy' => $session->accuracy,
            'updated_at' => $session->position_at?->toIso8601String(),
            'expires_at' => $session->expires_at->toIso8601String(),
            'hard_expires_at' => $session->hard_expires_at->toIso8601String(),
        ];

        if ($includeSessionId) {
            $data['session_id'] = $session->session_id;
        }

        return $data;
    }
}
