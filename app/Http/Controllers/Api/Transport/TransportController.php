<?php

namespace App\Http\Controllers\Api\Transport;

use App\Http\Controllers\Controller;
use App\Models\{Vehicle, Driver, Student, TimetableSlot, ClassRoom, VehicleTelemetry, GeofenceEvent};
use App\Models\Transport\{TransportRoute, TransportGeofence, TransportStop};
use App\Jobs\SendSmsJob;
use App\Services\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use App\Events\VehicleLocationUpdated;
use App\Events\GeofenceTriggered;
use App\Services\Transport\StopService;
use App\Models\Transport\TransportTrip;
use App\Models\Transport\TripLocation;
use Carbon\Carbon;

class TransportController extends Controller
{
    protected SmsService $sms;
    protected StopService $stopService;

    public function __construct(SmsService $sms, StopService $stopService)
    {
        $this->sms = $sms;
        $this->stopService = $stopService;
    }

    public function index(): JsonResponse
    {
        $routes = TransportRoute::where('school_id', currentSchoolId())
            ->with(['vehicle', 'driver', 'students'])
            ->withCount('students')
            ->get();

        return response()->json(['success' => true, 'data' => $routes]);
    }


    // Restored to live() to match your API routes and fixed the array/closure syntax
    public function live(): JsonResponse
    {
        // Gather all vehicles to show complete real-time fleet overview
        $vehicles = Vehicle::where('status', 'active')
            ->whereHas('currentRoute', fn($query) => $query->where('school_id', currentSchoolId()))
            ->with('currentRoute.driver')
            ->get();

        $formatted = $vehicles->map(function ($vehicle) {
            $assignedRoute = $vehicle->currentRoute;
            
            // Baseline Fallbacks centered on local Kenyan project coordinates if GPS telemetry fails
            return [
                'vehicle_id' => $vehicle->id,
                'number'     => $vehicle->registration_number,
                'route'      => $assignedRoute ? $assignedRoute->name : null,
                'driver'     => ($assignedRoute && $assignedRoute->driver) ? $assignedRoute->driver->name : null,
                'lat'        => $vehicle->last_lat ?? -1.2825, 
                'lng'        => $vehicle->last_lng ?? 36.8146,
                'speed'      => $vehicle->last_speed ?? 0,
                'updated_at' => $vehicle->location_updated_at 
                                    ? Carbon::parse($vehicle->location_updated_at)->toIso8601String() 
                                    : now()->toIso8601String(),
            ];
        });

        return response()->json(['success' => true, 'data' => $formatted]);
    }

    public function telemetryHistory(Request $request, $id): JsonResponse
    {
        $vehicle = Vehicle::where('id', $id)
            ->whereHas('currentRoute', fn($query) => $query->where('school_id', currentSchoolId()))
            ->firstOrFail();

        $query = VehicleTelemetry::where('vehicle_id', $vehicle->id);

        if ($request->filled('date')) {
            try {
                $date = Carbon::parse($request->query('date')); 
                $query->whereDate('recorded_at', $date->toDateString());
            } catch (\Throwable $e) {
                return response()->json(['success' => false, 'message' => 'Invalid date filter provided.'], 422);
            }
        }

        $history = $query->orderBy('recorded_at')
            ->limit(200)
            ->get()
            ->map(function ($entry) {
                return [
                    'vehicle_id'  => $entry->vehicle_id,
                    'lat'         => $entry->lat,
                    'lng'         => $entry->lng,
                    'speed'       => $entry->speed,
                    'recorded_at' => $entry->recorded_at ? $entry->recorded_at->toIso8601String() : null,
                ];
            });

        return response()->json(['success' => true, 'data' => $history]);
    }


    public function stopsForRoute($routeId): JsonResponse
    {
        $route = TransportRoute::where('school_id', currentSchoolId())->findOrFail($routeId);

        return response()->json(['success' => true, 'data' => $this->stopService->listStops($route)]);
    }

    public function storeStop(Request $request, $routeId): JsonResponse
    {
        $route = TransportRoute::where('school_id', currentSchoolId())->findOrFail($routeId);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'sequence' => 'required|integer|min:0',
            'pickup_time' => 'nullable|date_format:H:i',
            'dropoff_time' => 'nullable|date_format:H:i',
            'geofence_radius' => 'nullable|integer|min:25|max:500',
            'status' => 'nullable|in:active,inactive',
        ]);

        $stop = $this->stopService->createStop($route, $validated);

        return response()->json(['success' => true, 'data' => $stop], 201);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'       => 'required|string|max:255',
            'vehicle_id' => 'required|exists:vehicles,id',
            'driver_id'  => 'required|exists:drivers,id',
            'stops'      => 'required|array|min:1',
            'stops.*.name'         => 'required|string',
            'stops.*.pickup_time'  => 'required|date_format:H:i',
            'stops.*.drop_time'    => 'required|date_format:H:i',
            'monthly_fee'          => 'required|numeric|min:0',
        ]);

        $validated['school_id'] = currentSchoolId();

        $route = TransportRoute::create($validated);
        return response()->json(['success' => true, 'data' => $route], 201);
    }

    public function emergency(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'driver' => 'required|string|max:255',
            'vehicle' => 'required|string|max:255',
            'timestamp' => 'required|date',
            'gps' => 'nullable|array',
            'gps.lat' => 'nullable|numeric',
            'gps.lng' => 'nullable|numeric',
            'gps.speed' => 'nullable|numeric',
            'gps.heading' => 'nullable|numeric',
        ]);

        Log::warning('Transport emergency alert received', $validated);
        $this->notifyDispatchOfEmergency($validated);

        return response()->json([
            'success' => true,
            'message' => 'Emergency alert received. Dispatch has been notified.',
            'data' => $validated,
        ]);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $transportRoute = TransportRoute::where('school_id', currentSchoolId())->findOrFail($id);

        $validated = $request->validate([
            'name'       => 'sometimes|required|string|max:255',
            'vehicle_id' => [
                'sometimes',
                'required',
                Rule::exists('vehicles', 'id')->where(fn ($query) => $query->where('school_id', currentSchoolId())),
            ],
            'driver_id'  => [
                'sometimes',
                'required',
                Rule::exists('drivers', 'id')->where(fn ($query) => $query->where('school_id', currentSchoolId())),
            ],
            'stops'      => 'sometimes|required|array|min:1',
            'stops.*.name'         => 'required|string',
            'stops.*.pickup_time'  => 'required|date_format:H:i',
            'stops.*.drop_time'    => 'required|date_format:H:i',
            'monthly_fee'          => 'sometimes|required|numeric|min:0',
        ]);

        $transportRoute->update($validated);
        return response()->json(['success' => true, 'data' => $transportRoute->fresh()]);
    }

    public function destroy($id): JsonResponse
    {
        $transportRoute = TransportRoute::where('school_id', currentSchoolId())->findOrFail($id);
        $transportRoute->delete();
        
        return response()->json(['success' => true, 'message' => 'Route deleted.']);
    }

    public function show($id): JsonResponse
    {
        $transportRoute = TransportRoute::with(['vehicle', 'driver', 'students.classRoom'])
            ->where('school_id', currentSchoolId())
            ->findOrFail($id);

        return response()->json(['success' => true, 'data' => $transportRoute]);
    }

    public function myRoute(): JsonResponse
    {
        $user = auth()->user();

        if (! $user || ! $user->isDriver() || ! $user->driver) {
            return response()->json([
                'success' => true,
                'data' => null,
            ]);
        }

        $route = TransportRoute::where('school_id', currentSchoolId())
            ->where('driver_id', $user->driver->id)
            ->with(['vehicle', 'driver', 'students.parentProfile'])
            ->first();

        if (! $route) {
            return response()->json(['success' => true, 'data' => null]);
        }

        $routeData = $route->toArray();
        $routeData['eta'] = $this->calculateRouteEta($route->stops);
        $routeData['distance_text'] = 'Distance unavailable';

        return response()->json(['success' => true, 'data' => $routeData]);
    }

    public function myGeofences(): JsonResponse
    {
        $user = auth()->user();

        if (! $user || ! $user->isDriver() || ! $user->driver) {
            return response()->json(['success' => false, 'message' => 'Unauthorized driver.'], 403);
        }

        $route = TransportRoute::where('school_id', currentSchoolId())
            ->where('driver_id', $user->driver->id)
            ->with(['geofences'])
            ->first();

        if (! $route) {
            return response()->json(['success' => true, 'data' => []]);
        }

        return response()->json(['success' => true, 'data' => $route->geofences]);
    }

    public function myGeofenceEvents(): JsonResponse
    {
        $user = auth()->user();

        if (! $user || ! $user->isDriver() || ! $user->driver) {
            return response()->json(['success' => false, 'message' => 'Unauthorized driver.'], 403);
        }

        $route = TransportRoute::where('school_id', currentSchoolId())
            ->where('driver_id', $user->driver->id)
            ->first();

        if (! $route) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $events = GeofenceEvent::where('transport_route_id', $route->id)
            ->where('vehicle_id', $route->vehicle_id)
            ->orderByDesc('triggered_at')
            ->limit(50)
            ->get();

        return response()->json(['success' => true, 'data' => $events]);
    }

    public function geofencesForRoute($routeId): JsonResponse
    {
        $route = TransportRoute::where('school_id', currentSchoolId())->findOrFail($routeId);
        $geofences = TransportGeofence::where('transport_route_id', $route->id)
            ->where('school_id', currentSchoolId())
            ->get();

        return response()->json(['success' => true, 'data' => $geofences]);
    }

    public function storeGeofence(Request $request, $routeId): JsonResponse
    {
        $route = TransportRoute::where('school_id', currentSchoolId())->findOrFail($routeId);

        $validated = $request->validate([
            'stop_name' => 'required|string|max:255',
            'stop_type' => 'required|in:Pickup,Drop',
            'lat' => 'required|numeric',
            'lng' => 'required|numeric',
            'radius_meters' => 'required|integer|min:25|max:500',
            'active' => 'sometimes|boolean',
        ]);

        $geofence = TransportGeofence::create([
            'school_id' => currentSchoolId(),
            'transport_route_id' => $route->id,
            'stop_name' => $validated['stop_name'],
            'stop_type' => $validated['stop_type'],
            'lat' => $validated['lat'],
            'lng' => $validated['lng'],
            'radius_meters' => $validated['radius_meters'],
            'active' => $validated['active'] ?? true,
        ]);

        return response()->json(['success' => true, 'data' => $geofence], 201);
    }

    public function myRouteEta(): JsonResponse
    {
        $user = auth()->user();

        if (! $user || ! $user->isDriver() || ! $user->driver) {
            return response()->json(['success' => false, 'message' => 'Unauthorized driver.'], 403);
        }

        $route = TransportRoute::where('school_id', currentSchoolId())
            ->where('driver_id', $user->driver->id)
            ->first();

        if (! $route) {
            return response()->json(['success' => true, 'data' => [
                'route_id' => null,
                'route_name' => null,
                'eta' => null,
                'events' => [],
                'remaining_events' => 0,
            ]]);
        }

        $eta = $this->calculateRouteEta($route->stops);

        return response()->json(['success' => true, 'data' => [
            'route_id' => $route->id,
            'route_name' => $route->name,
            'eta' => $eta,
            'events' => $eta['events'],
            'remaining_events' => $eta['remaining_events'],
        ]]);
    }

    private function resolveDriverOrAdminRoute($user): ?TransportRoute
    {
        if (! $user) {
            return null;
        }

        if ($user->isDriver() && $user->driver) {
            return TransportRoute::with(['vehicle', 'driver', 'students', 'geofences'])
                ->where('school_id', currentSchoolId())
                ->where('driver_id', $user->driver->id)
                ->first();
        }

        if ($user->isAdmin() || $user->isSuperAdmin()) {
            return TransportRoute::with(['vehicle', 'driver', 'students', 'geofences'])
                ->where('school_id', currentSchoolId())
                ->orderBy('id')
                ->first();
        }

        return null;
    }

    public function transportAnalyticsOverview(Request $request): JsonResponse
    {
        $user = auth()->user();
        $route = $this->resolveDriverOrAdminRoute($user);

        if (! $route) {
            return response()->json(['success' => true, 'data' => null]);
        }

        if (! $route) {
            return response()->json(['success' => true, 'data' => null]);
        }

        $date = $this->parseAnalyticsDate($request);
        $telemetry = VehicleTelemetry::where('vehicle_id', $route->vehicle_id)
            ->whereDate('recorded_at', $date)
            ->orderBy('recorded_at')
            ->get();

        $events = GeofenceEvent::where('transport_route_id', $route->id)
            ->whereDate('triggered_at', $date)
            ->get();

        $metrics = $this->buildRouteTelemetryMetrics($telemetry);
        $efficiencyScore = $this->calculateEfficiencyScore($metrics);

        $stopCount = $route->geofences->count();
        $completedStops = $events->where('event_type', 'enter')->count();
        $completionPct = $stopCount > 0 ? round(($completedStops / $stopCount) * 100, 1) : 0.0;

        return response()->json(['success' => true, 'data' => [
            'route_id' => $route->id,
            'route_name' => $route->name,
            'vehicle' => $route->vehicle?->registration_number,
            'driver_name' => $route->driver?->name,
            'date' => $date,
            'metrics' => $metrics,
            'efficiency_score' => $efficiencyScore,
            'stop_count' => $stopCount,
            'completed_stops' => $completedStops,
            'completion_pct' => $completionPct,
            'geofence_events' => $events->count(),
        ]]);
    }

    public function transportDriverRanking(Request $request): JsonResponse
    {
        $date = $this->parseAnalyticsDate($request);
        $routes = TransportRoute::with(['driver', 'vehicle'])
            ->where('school_id', currentSchoolId())
            ->get();

        $driverRoutes = $routes->groupBy('driver_id');
        $vehicleIds = $routes->pluck('vehicle_id')->filter()->unique()->values()->all();

        $telemetryByVehicle = VehicleTelemetry::whereIn('vehicle_id', $vehicleIds)
            ->whereDate('recorded_at', $date)
            ->orderBy('recorded_at')
            ->get()
            ->groupBy('vehicle_id');

        $ranking = $driverRoutes->map(function ($routes, $driverId) use ($telemetryByVehicle) {
            $driver = $routes->first()->driver;
            $routeSummaries = $routes->map(function ($route) use ($telemetryByVehicle) {
                $metrics = $this->buildRouteTelemetryMetrics(
                    $telemetryByVehicle->get($route->vehicle_id, collect())
                );
                return [
                    'route_name' => $route->name,
                    'vehicle' => $route->vehicle?->registration_number,
                    'telemetry_points' => $metrics['telemetry_points'],
                    'average_speed' => $metrics['average_speed'],
                    'distance_km' => $metrics['distance_km'],
                    'idle_minutes' => $metrics['idle_minutes'],
                    'efficiency_score' => $this->calculateEfficiencyScore($metrics),
                ];
            });

            return [
                'driver_id' => $driverId,
                'driver_name' => $driver?->name,
                'routes' => $routeSummaries->values(),
                'route_count' => $routeSummaries->count(),
                'average_efficiency' => round($routeSummaries->avg(fn ($item) => $item['efficiency_score'] ?? 0), 1),
                'total_distance_km' => round($routeSummaries->sum(fn ($item) => $item['distance_km'] ?? 0), 1),
            ];
        })->sortByDesc('average_efficiency')->values();

        return response()->json(['success' => true, 'data' => $ranking]);
    }

    public function transportHeatMap(Request $request): JsonResponse
    {
        $user = auth()->user();
        $route = $this->resolveDriverOrAdminRoute($user);

        if (! $route) {
            return response()->json(['success' => true, 'data' => ['points' => []]]);
        }

        $date = $this->parseAnalyticsDate($request);
        $telemetry = VehicleTelemetry::where('vehicle_id', $route->vehicle_id)
            ->whereDate('recorded_at', $date)
            ->orderBy('recorded_at')
            ->limit(500)
            ->get();

        $points = $telemetry->map(function ($item) {
            return [
                'lat' => (float) $item->lat,
                'lng' => (float) $item->lng,
                'speed' => $item->speed,
                'recorded_at' => $item->recorded_at?->toIso8601String(),
            ];
        })->values();

        return response()->json(['success' => true, 'data' => [
            'route_id' => $route->id,
            'route_name' => $route->name,
            'vehicle' => $route->vehicle?->registration_number,
            'points' => $points,
        ]]);
    }

    public function transportAnalyticsPrediction(Request $request): JsonResponse
    {
        $user = auth()->user();
        $route = $this->resolveDriverOrAdminRoute($user);

        if (! $route) {
            return response()->json(['success' => true, 'data' => null]);
        }

        $date = $this->parseAnalyticsDate($request);
        $telemetry = VehicleTelemetry::where('vehicle_id', $route->vehicle_id)
            ->whereDate('recorded_at', $date)
            ->orderBy('recorded_at')
            ->get();

        $events = GeofenceEvent::where('transport_route_id', $route->id)
            ->whereDate('triggered_at', $date)
            ->where('event_type', 'enter')
            ->get();

        $metrics = $this->buildRouteTelemetryMetrics($telemetry);

            \Log::info('TRANSPORT PREDICTION DEBUG', [
            'metrics' => $metrics,
            'events' => $events->map(fn ($event) => [
                'triggered_at' => $event->triggered_at,
                'payload' => $event->payload,
            ])->toArray(),
            'route_stops' => $route->stops,
            'timezone' => now()->getTimezone()->getName(),
        ]);

        $prediction = $this->buildRouteDelayPrediction($route, $telemetry, $events, $metrics);

        return response()->json(['success' => true, 'data' => $prediction]);
    }

    private function buildRouteDelayPrediction(TransportRoute $route, $telemetry, $events, array $metrics): array
    {
        $baseDelay = $this->calculateAverageStopDelay($route, $events);
        $speedPenalty = max(0, 25 - $metrics['average_speed']) * 0.23;
        $idlePenalty = $metrics['idle_minutes'] * 1.0;
        $predictedDelay = max(0, (int) round($baseDelay + $speedPenalty + $idlePenalty));
        $confidence = max(30, min(95, 100 - ($predictedDelay * 2)));

        if ($predictedDelay <= 5) {
            $status = 'On track';
        } elseif ($predictedDelay <= 15) {
            $status = 'Minor delay';
        } else {
            $status = 'Delayed';
        }

        return [
            'route_id' => $route->id,
            'route_name' => $route->name,
            'vehicle' => $route->vehicle?->registration_number,
            'driver_name' => $route->driver?->name,
            'predicted_delay_minutes' => $predictedDelay,
            'status' => $status,
            'confidence' => $confidence,
            'basis' => [
                'average_speed' => $metrics['average_speed'],
                'idle_minutes' => $metrics['idle_minutes'],
                'stop_delay' => round($baseDelay, 1),
            ],
        ];
    }

    private function calculateAverageStopDelay(TransportRoute $route, $events): float
    {
        $scheduleMap = [];
        $routeStops = $route->stops ?? [];

        foreach ($routeStops as $stop) {
            $stopName = $stop['name'] ?? null;
            if (! $stopName) {
                continue;
            }

            if (! empty($stop['pickup_time'])) {
                $scheduleMap["{$stopName}|Pickup"] = $stop['pickup_time'];
            }

            if (! empty($stop['drop_time'])) {
                $scheduleMap["{$stopName}|Drop"] = $stop['drop_time'];
            }
        }

        $delays = [];

        foreach ($events as $event) {
            $payload = is_array($event->payload) ? $event->payload : json_decode($event->payload, true);
            $stopName = $payload['stop_name'] ?? null;
            $stopType = $payload['stop_type'] ?? null;
            $scheduledKey = "{$stopName}|{$stopType}";

            if (empty($stopName) || empty($stopType) || ! isset($scheduleMap[$scheduledKey])) {
                continue;
            }

            try {
                $scheduled = $this->parseScheduleTime($scheduleMap[$scheduledKey], now()->getTimezone());
                $actual = Carbon::parse($event->triggered_at);
                if (! $scheduled) {
                    continue;
                }
                $delayMinutes = $scheduled->diffInMinutes($actual, false);
                if ($delayMinutes > 0) {
                    $delays[] = $delayMinutes;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        if (empty($delays)) {
            return 0.0;
        }

        return round(array_sum($delays) / count($delays), 1);
    }

    private function parseScheduleTime(string $time, \DateTimeZone $timezone): ?Carbon
    {
        if (trim($time) === '') {
            return null;
        }

        if (preg_match('/^\d{1,2}:\d{2}$/', $time)) {
            return Carbon::createFromFormat('H:i', $time, $timezone);
        }

        try {
            return Carbon::parse($time)->setTimezone($timezone);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function parseAnalyticsDate(Request $request): string
    {
        if (! $request->filled('date')) {
            return now()->toDateString();
        }

        try {
            return Carbon::parse($request->query('date'))->toDateString();
        } catch (\Throwable $e) {
            abort(422, 'Invalid date filter provided.');
        }
    }

    private function buildRouteTelemetryMetrics($telemetry): array
    {
        $count = $telemetry->count();
        $averageSpeed = $count > 0 ? round($telemetry->avg('speed'), 1) : 0.0;
        $maxSpeed = $count > 0 ? (int) $telemetry->max('speed') : 0;
        $distanceKm = $this->calculateTelemetryDistanceKm($telemetry);
        $idleSeconds = 0;

        for ($i = 0; $i < $count - 1; $i++) {
            $current = $telemetry[$i];
            $next = $telemetry[$i + 1];
            $deltaSeconds = Carbon::parse($next->recorded_at)->diffInSeconds(Carbon::parse($current->recorded_at));
            if ($current->speed !== null && $current->speed < 5 && $deltaSeconds > 0) {
                $idleSeconds += $deltaSeconds;
            }
        }

        return [
            'telemetry_points' => $count,
            'average_speed' => $averageSpeed,
            'max_speed' => $maxSpeed,
            'distance_km' => round($distanceKm, 2),
            'idle_minutes' => (int) round($idleSeconds / 60),
        ];
    }

    private function calculateTelemetryDistanceKm($telemetry): float
    {
        $distance = 0.0;
        $count = $telemetry->count();

        for ($i = 0; $i < $count - 1; $i++) {
            $a = $telemetry[$i];
            $b = $telemetry[$i + 1];
            $distance += $this->calculateDistance((float) $a->lat, (float) $a->lng, (float) $b->lat, (float) $b->lng) / 1000;
        }

        return $distance;
    }

    private function calculateEfficiencyScore(array $metrics): int
    {
        $score = 100;
        $score -= min(40, $metrics['idle_minutes'] * 1.2);
        $score -= $metrics['average_speed'] < 25 ? 15 : 0;
        $score -= $metrics['max_speed'] > 80 ? 10 : 0;
        return max(0, min(100, (int) round($score)));
    }

    private function calculateRouteEta(array $stops): array
    {
        $now = Carbon::now();
        $events = [];

        foreach ($stops as $stop) {
            $name = $stop['name'] ?? 'Unknown stop';

            if (! empty($stop['pickup_time'])) {
                $events[] = [
                    'name' => $name,
                    'type' => 'Pickup',
                    'time' => Carbon::createFromFormat('H:i', $stop['pickup_time'], $now->getTimezone()),
                ];
            }

            if (! empty($stop['drop_time'])) {
                $events[] = [
                    'name' => $name,
                    'type' => 'Drop',
                    'time' => Carbon::createFromFormat('H:i', $stop['drop_time'], $now->getTimezone()),
                ];
            }
        }

        usort($events, function ($a, $b) {
            return $a['time']->greaterThan($b['time']) ? 1 : ($a['time']->lessThan($b['time']) ? -1 : 0);
        });

        $formatted = array_map(function ($event) use ($now) {
            return [
                'name' => $event['name'],
                'type' => $event['type'],
                'time' => $event['time']->format('H:i'),
                'status' => $event['time']->lessThanOrEqualTo($now) ? 'past' : 'upcoming',
            ];
        }, $events);

        if (empty($events)) {
            return [
                'next_stop' => null,
                'next_type' => null,
                'next_time' => null,
                'eta_minutes' => null,
                'eta_text' => 'No schedule available',
                'events' => [],
                'remaining_events' => 0,
            ];
        }

        $nextEvent = null;
        foreach ($events as $event) {
            if ($event['time']->greaterThan($now)) {
                $nextEvent = $event;
                break;
            }
        }

        if (! $nextEvent) {
            $lastEvent = end($events);
            return [
                'next_stop' => $lastEvent['name'],
                'next_type' => $lastEvent['type'],
                'next_time' => $lastEvent['time']->format('H:i'),
                'eta_minutes' => 0,
                'eta_text' => 'Route completed for today',
                'events' => $formatted,
                'remaining_events' => 0,
            ];
        }

        $etaMinutes = max(0, $now->diffInMinutes($nextEvent['time']));
        $remaining = array_filter($events, fn($event) => $event['time']->greaterThan($now));

        return [
            'next_stop' => $nextEvent['name'],
            'next_type' => $nextEvent['type'],
            'next_time' => $nextEvent['time']->format('H:i'),
            'eta_minutes' => $etaMinutes,
            'eta_text' => $etaMinutes > 0 ? "{$etaMinutes} mins" : 'Arriving now',
            'events' => $formatted,
            'remaining_events' => count($remaining),
        ];
    }

    public function updateTelemetry(Request $request, $id): JsonResponse
    {
        $vehicle = \App\Models\Vehicle::findOrFail($id);
        $user = auth()->user();

        if (! $user || ! $user->isDriver() || ! $user->driver) {
            return response()->json(['success' => false, 'message' => 'Unauthorized driver.'], 403);
        }

        $assignedRoute = TransportRoute::where('school_id', currentSchoolId())
            ->where('driver_id', $user->driver->id)
            ->where('vehicle_id', $vehicle->id)
            ->with('geofences')
            ->first();

        if (! $assignedRoute) {
            return response()->json(['success' => false, 'message' => 'Vehicle is not assigned to this authenticated driver.'], 403);
        }

        $request->validate([
            'lat'      => 'required|numeric',
            'lng'      => 'required|numeric',
            'speed'    => 'required|integer|min:0',
            'heading'  => 'nullable|numeric',
            'accuracy' => 'nullable|numeric|min:0',
        ]);

        // Update the vehicle coordinates in the database
        $vehicle->update([
            'last_lat'            => $request->lat,
            'last_lng'            => $request->lng,
            'last_speed'          => $request->speed,
            'location_updated_at' => now(),
        ]);

        VehicleTelemetry::create([
            'vehicle_id'  => $vehicle->id,
            'lat'         => $request->lat,
            'lng'         => $request->lng,
            'speed'       => $request->speed,
            'recorded_at' => now(),
        ]);

        $activeTrip = TransportTrip::where('school_id', currentSchoolId())
            ->where('transport_route_id', $assignedRoute->id)
            ->where('vehicle_id', $vehicle->id)
            ->where('driver_id', $user->driver->id)
            ->where('status', TransportTrip::STATUS_IN_PROGRESS)
            ->first();

        if ($activeTrip) {
            TripLocation::create([
                'transport_trip_id' => $activeTrip->id,
                'lat' => $request->lat,
                'lng' => $request->lng,
                'speed' => $request->speed,
                'heading' => $request->heading,
                'accuracy' => $request->accuracy,
                'recorded_at' => now(),
            ]);
        }

        $this->checkGeofenceTriggers($assignedRoute, $vehicle, (float) $request->lat, (float) $request->lng);
        $this->checkOffRoute($assignedRoute, $vehicle, (float) $request->lat, (float) $request->lng);

        // Load relationships needed by your event payload formatting if necessary
        $vehicle->load(['currentRoute.driver']);

        // Broadcast the real-time event via Reverb/Pusher immediately
        broadcast(new VehicleLocationUpdated($vehicle));

        return response()->json([
            'success' => true,
            'message' => 'Telemetry updated and broadcast successfully.',
            'data'    => [
                'lat'   => $vehicle->last_lat,
                'lng'   => $vehicle->last_lng,
                'speed' => $vehicle->last_speed,
            ]
        ]);
    }

    private function checkGeofenceTriggers(TransportRoute $route, Vehicle $vehicle, float $lat, float $lng): void
    {
        foreach ($route->geofences->where('active', true) as $geofence) {
            $inside = $this->isPointInsideGeofence($lat, $lng, $geofence->lat, $geofence->lng, $geofence->radius_meters);
            $lastEvent = GeofenceEvent::where('transport_route_id', $route->id)
                ->where('transport_geofence_id', $geofence->id)
                ->where('vehicle_id', $vehicle->id)
                ->orderByDesc('triggered_at')
                ->first();

            $wasInside = $lastEvent && $lastEvent->event_type === 'enter';

            if ($inside && ! $wasInside) {
                $this->recordGeofenceEvent($route, $vehicle, $geofence, 'enter', $lat, $lng);
            }

            if (! $inside && $wasInside) {
                $this->recordGeofenceEvent($route, $vehicle, $geofence, 'exit', $lat, $lng);
            }
        }
    }

    private function isPointInsideGeofence(float $lat, float $lng, float $centerLat, float $centerLng, int $radiusMeters): bool
    {
        $earthRadius = 6371000;
        $dLat = deg2rad($centerLat - $lat);
        $dLng = deg2rad($centerLng - $lng);
        $a = sin($dLat / 2) * sin($dLat / 2) + cos(deg2rad($lat)) * cos(deg2rad($centerLat)) * sin($dLng / 2) * sin($dLng / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        $distance = $earthRadius * $c;

        return $distance <= $radiusMeters;
    }

    private function handleStopNotification(TransportRoute $route, Vehicle $vehicle, TransportGeofence $geofence, string $eventType): void
    {
        $stopName = $geofence->stop_name;
        $eventName = $eventType === 'enter' ? 'arrived at' : 'left';
        $message = "Route {$route->name}: Vehicle {$vehicle->registration_number} has {$eventName} {$stopName} ({$geofence->stop_type}).";

        $contacts = $route->students()
            ->wherePivot('stop', $stopName)
            ->with('parentProfile')
            ->get()
            ->map(function (Student $student) {
                return $student->parentProfile?->phone;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (! empty($contacts)) {
            $this->dispatchTransportSmsNotifications($route->school_id, $contacts, $message);
        }

        $dispatchPhone = $this->getTransportDispatchPhone();
        if (! empty($dispatchPhone)) {
            $dispatchMessage = "Transport alert: Vehicle {$vehicle->registration_number} {$eventName} {$stopName} on route {$route->name}.";
            $this->dispatchTransportSmsNotifications($route->school_id, [$dispatchPhone], $dispatchMessage);
        }
    }

    private function dispatchTransportSmsNotifications(?int $schoolId, array $phones, string $message): void
    {
        $phones = array_filter(array_unique(array_map('trim', $phones)));
        foreach ($phones as $phone) {
            if (empty($phone)) {
                continue;
            }
            SendSmsJob::dispatch($schoolId, null, $phone, $message);
        }
    }

    private function isOffRoute(TransportRoute $route, float $lat, float $lng): bool
    {
        if ($route->geofences->isEmpty()) {
            return false;
        }

        $buffer = $this->getOffRouteBuffer();
        foreach ($route->geofences->where('active', true) as $geofence) {
            $distance = $this->calculateDistance($lat, $lng, $geofence->lat, $geofence->lng);
            if ($distance <= $geofence->radius_meters + $buffer) {
                return false;
            }
        }

        return true;
    }

    private function getTransportDispatchPhone(): ?string
    {
        return config('transport.dispatch_phone');
    }

    private function getOffRouteBuffer(): int
    {
        return (int) config('transport.off_route_radius_buffer_meters', 500);
    }

    private function calculateDistance(float $lat, float $lng, float $centerLat, float $centerLng): float
    {
        $earthRadius = 6371000;
        $dLat = deg2rad($centerLat - $lat);
        $dLng = deg2rad($centerLng - $lng);
        $a = sin($dLat / 2) * sin($dLat / 2) + cos(deg2rad($lat)) * cos(deg2rad($centerLat)) * sin($dLng / 2) * sin($dLng / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }

    private function checkOffRoute(TransportRoute $route, Vehicle $vehicle, float $lat, float $lng): void
    {
        if (! $this->isOffRoute($route, $lat, $lng)) {
            Cache::forget("transport-offroute-{$vehicle->id}");
            return;
        }

        $cacheKey = "transport-offroute-{$vehicle->id}";
        if (Cache::has($cacheKey)) {
            return;
        }

        $dispatchPhone = $this->getTransportDispatchPhone();
        if (! empty($dispatchPhone)) {
            $message = "Off-route alert: Vehicle {$vehicle->registration_number} on route {$route->name} is outside the expected area at {$lat}, {$lng}.";
            $this->dispatchTransportSmsNotifications($route->school_id, [$dispatchPhone], $message);
        }

        Cache::put($cacheKey, true, now()->addMinutes(10));
    }

    private function notifyDispatchOfEmergency(array $payload): void
    {
        $dispatchPhone = $this->getTransportDispatchPhone();
        if (empty($dispatchPhone)) {
            return;
        }

        $locationText = '';
        if (! empty($payload['gps']['lat']) && ! empty($payload['gps']['lng'])) {
            $locationText = " at {$payload['gps']['lat']}, {$payload['gps']['lng']}";
        }

        $message = "Emergency alert from driver {$payload['driver']} in vehicle {$payload['vehicle']}{$locationText}. Please respond immediately.";
        $this->dispatchTransportSmsNotifications(currentSchoolId(), [$dispatchPhone], $message);
    }

    private function recordGeofenceEvent(TransportRoute $route, Vehicle $vehicle, TransportGeofence $geofence, string $eventType, float $lat, float $lng): void
    {
        $event = GeofenceEvent::create([
            'school_id' => currentSchoolId(),
            'transport_route_id' => $route->id,
            'transport_geofence_id' => $geofence->id,
            'vehicle_id' => $vehicle->id,
            'event_type' => $eventType,
            'lat' => $lat,
            'lng' => $lng,
            'triggered_at' => now(),
            'payload' => [
                'stop_name' => $geofence->stop_name,
                'stop_type' => $geofence->stop_type,
                'radius_meters' => $geofence->radius_meters,
            ],
        ]);

        broadcast(new GeofenceTriggered([
            'route_id' => $route->id,
            'route_name' => $route->name,
            'vehicle_id' => $vehicle->id,
            'event_type' => $eventType,
            'geofence_id' => $geofence->id,
            'stop_name' => $geofence->stop_name,
            'stop_type' => $geofence->stop_type,
            'lat' => $lat,
            'lng' => $lng,
            'triggered_at' => $event->triggered_at->toIso8601String(),
            'payload' => $event->payload,
        ], $route->school_id));
    }
}