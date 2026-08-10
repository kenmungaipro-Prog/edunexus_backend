<?php

use App\Events\VehicleLocationUpdated;
use App\Models\Driver;
use App\Models\School;
use App\Models\Transport\TransportRoute;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleTelemetry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use App\Models\GeofenceEvent;
use App\Models\Transport\TransportGeofence;
use App\Models\Transport\TransportTrip;
use App\Models\Transport\TripLocation;



uses(RefreshDatabase::class);


function createLiveTrackingDriverContext(): array
{
    $school = School::factory()->create();

    $user = User::factory()->create([
        'school_id' => $school->id,
        'role' => 'driver',
        'status' => 'active',
    ]);

    $driver = Driver::create([
        'user_id' => $user->id,
        'name' => 'Live Tracking Driver',
        'phone' => '+1234567890',
        'license_no' => 'DRV-LIVE-001',
        'license_expiry' => Carbon::now()->addYear()->toDateString(),
        'status' => 'active',
    ]);

    $vehicle = Vehicle::create([
        'school_id' => $school->id,
        'registration_number' => 'LIVE-001',
        'make' => 'TestCo',
        'model' => 'Live X',
        'capacity' => 24,
        'status' => 'active',
    ]);

    $route = TransportRoute::create([
        'school_id' => $school->id,
        'name' => 'Live Tracking Route',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'stops' => [
            [
                'name' => 'Main Stop',
                'pickup_time' => '08:00',
                'drop_time' => '17:00',
            ],
        ],
        'monthly_fee' => 0,
    ]);

    return compact(
        'school',
        'user',
        'driver',
        'vehicle',
        'route'
    );
}

test('assigned driver can submit vehicle telemetry', function () {
    $context = createLiveTrackingDriverContext();

    $response = $this->actingAs($context['user'])
        ->postJson(
            "/api/v1/transport/vehicles/{$context['vehicle']->id}/telemetry",
            [
                'lat' => -1.2825,
                'lng' => 36.8146,
                'speed' => 35,
            ]
        );

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.lat', -1.2825)
        ->assertJsonPath('data.lng', 36.8146)
        ->assertJsonPath('data.speed', 35);
});

test('telemetry update persists latest vehicle location', function () {
    $context = createLiveTrackingDriverContext();

    $this->actingAs($context['user'])
        ->postJson(
            "/api/v1/transport/vehicles/{$context['vehicle']->id}/telemetry",
            [
                'lat' => -1.2801,
                'lng' => 36.8172,
                'speed' => 42,
            ]
        )
        ->assertStatus(200);

    $context['vehicle']->refresh();

    expect((float) $context['vehicle']->last_lat)->toBe(-1.2801)
        ->and((float) $context['vehicle']->last_lng)->toBe(36.8172)
        ->and((int) $context['vehicle']->last_speed)->toBe(42)
        ->and($context['vehicle']->location_updated_at)->not->toBeNull();
});

test('telemetry update creates a vehicle telemetry history record', function () {
    $context = createLiveTrackingDriverContext();

    $this->actingAs($context['user'])
        ->postJson(
            "/api/v1/transport/vehicles/{$context['vehicle']->id}/telemetry",
            [
                'lat' => -1.2815,
                'lng' => 36.8165,
                'speed' => 28,
            ]
        )
        ->assertStatus(200);

    $this->assertDatabaseHas('vehicle_telemetry', [
        'vehicle_id' => $context['vehicle']->id,
        'lat' => -1.2815,
        'lng' => 36.8165,
        'speed' => 28,
    ]);
});

test('non-driver cannot submit vehicle telemetry', function () {
    $school = School::factory()->create();

    $user = User::factory()->create([
        'school_id' => $school->id,
        'role' => 'parent',
        'status' => 'active',
    ]);

    $vehicle = Vehicle::create([
        'school_id' => $school->id,
        'registration_number' => 'LIVE-002',
        'make' => 'TestCo',
        'model' => 'Live X',
        'capacity' => 24,
        'status' => 'active',
    ]);

    $response = $this->actingAs($user)
        ->postJson(
            "/api/v1/transport/vehicles/{$vehicle->id}/telemetry",
            [
                'lat' => -1.2825,
                'lng' => 36.8146,
                'speed' => 20,
            ]
        );

    $response->assertStatus(403)
        ->assertJsonPath('success', false);

    $this->assertDatabaseCount('vehicle_telemetry', 0);
});

test('driver cannot submit telemetry for another drivers vehicle', function () {
    $context = createLiveTrackingDriverContext();

    $otherUser = User::factory()->create([
        'school_id' => $context['school']->id,
        'role' => 'driver',
        'status' => 'active',
    ]);

    Driver::create([
        'user_id' => $otherUser->id,
        'name' => 'Other Driver',
        'phone' => '+1234567891',
        'license_no' => 'DRV-LIVE-002',
        'license_expiry' => Carbon::now()->addYear()->toDateString(),
        'status' => 'active',
    ]);

    $response = $this->actingAs($otherUser)
        ->postJson(
            "/api/v1/transport/vehicles/{$context['vehicle']->id}/telemetry",
            [
                'lat' => -1.2825,
                'lng' => 36.8146,
                'speed' => 20,
            ]
        );

    $response->assertStatus(403)
        ->assertJsonPath('success', false);

    $this->assertDatabaseCount('vehicle_telemetry', 0);

});
test('telemetry update dispatches VehicleLocationUpdated event', function () {
    Event::fake([
        VehicleLocationUpdated::class,
    ]);

    $context = createLiveTrackingDriverContext();

    $this->actingAs($context['user'])
        ->postJson(
            "/api/v1/transport/vehicles/{$context['vehicle']->id}/telemetry",
            [
                'lat' => -1.2825,
                'lng' => 36.8146,
                'speed' => 35,
            ]
        )
        ->assertStatus(200);

    Event::assertDispatched(
        VehicleLocationUpdated::class,
        function (VehicleLocationUpdated $event) use ($context) {
            return $event->vehicle['vehicle_id'] === $context['vehicle']->id
                && $event->vehicle['number'] === 'LIVE-001'
                && $event->vehicle['route'] === 'Live Tracking Route'
                && $event->vehicle['driver'] === 'Live Tracking Driver'
                && (float) $event->vehicle['lat'] === -1.2825
                && (float) $event->vehicle['lng'] === 36.8146
                && (int) $event->vehicle['speed'] === 35;
        }
    );
});

test('VehicleLocationUpdated uses the schools fleet channel', function () {
    $context = createLiveTrackingDriverContext();

    $context['vehicle']->refresh();
    $context['vehicle']->load('currentRoute.driver');

    $event = new VehicleLocationUpdated($context['vehicle']);

    expect($event->broadcastOn()->name)
        ->toBe("fleet-delivery.{$context['school']->id}");
});

test('VehicleLocationUpdated uses the expected broadcast name', function () {
    $context = createLiveTrackingDriverContext();

    $context['vehicle']->load('currentRoute.driver');

    $event = new VehicleLocationUpdated($context['vehicle']);

    expect($event->broadcastAs())
        ->toBe('vehicle.location.updated');
});

test('live transport endpoint returns active vehicles for the authenticated school', function () {
    $context = createLiveTrackingDriverContext();

    $response = $this->actingAs($context['user'])
        ->getJson('/api/v1/transport/live');

    $response->assertStatus(200)
        ->assertJsonPath('success', true);

    $response->assertJsonCount(1, 'data');

    $response->assertJsonPath('data.0.vehicle_id', $context['vehicle']->id)
        ->assertJsonPath('data.0.number', 'LIVE-001')
        ->assertJsonPath('data.0.route', 'Live Tracking Route')
        ->assertJsonPath('data.0.driver', 'Live Tracking Driver');
});

test('live transport endpoint returns the vehicles latest telemetry values', function () {
    $context = createLiveTrackingDriverContext();

    $context['vehicle']->update([
        'last_lat' => -1.2901,
        'last_lng' => 36.8215,
        'last_speed' => 47,
        'location_updated_at' => Carbon::parse('2026-08-09 08:30:00'),
    ]);

    $response = $this->actingAs($context['user'])
        ->getJson('/api/v1/transport/live');

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.0.lat', -1.2901)
        ->assertJsonPath('data.0.lng', 36.8215)
        ->assertJsonPath('data.0.speed', 47)
        ->assertJsonPath(
            'data.0.updated_at',
            Carbon::parse('2026-08-09 08:30:00')->toIso8601String()
        );
});

test('live transport endpoint excludes inactive and other school vehicles', function () {
    $context = createLiveTrackingDriverContext();

    $context['vehicle']->update([
        'status' => 'inactive',
    ]);

    $otherSchool = School::factory()->create();

    $otherUser = User::factory()->create([
        'school_id' => $otherSchool->id,
        'role' => 'driver',
        'status' => 'active',
    ]);

    $otherDriver = Driver::create([
        'user_id' => $otherUser->id,
        'name' => 'Other School Driver',
        'phone' => '+1234567892',
        'license_no' => 'DRV-OTHER-001',
        'license_expiry' => Carbon::now()->addYear()->toDateString(),
        'status' => 'active',
    ]);

    $otherVehicle = Vehicle::create([
        'school_id' => $otherSchool->id,
        'registration_number' => 'OTHER-001',
        'make' => 'OtherCo',
        'model' => 'Other X',
        'capacity' => 20,
        'status' => 'active',
    ]);

    TransportRoute::create([
        'school_id' => $otherSchool->id,
        'name' => 'Other School Route',
        'vehicle_id' => $otherVehicle->id,
        'driver_id' => $otherDriver->id,
        'stops' => [
            [
                'name' => 'Other Stop',
                'pickup_time' => '08:00',
                'drop_time' => '17:00',
            ],
        ],
        'monthly_fee' => 0,
    ]);

    $response = $this->actingAs($context['user'])
        ->getJson('/api/v1/transport/live');

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonCount(0, 'data');
});

test('telemetry history returns the vehicles recorded locations in chronological order', function () {
    $context = createLiveTrackingDriverContext();

    VehicleTelemetry::create([
        'vehicle_id' => $context['vehicle']->id,
        'lat' => -1.2850,
        'lng' => 36.8150,
        'speed' => 20,
        'recorded_at' => Carbon::parse('2026-08-09 08:10:00'),
    ]);

    VehicleTelemetry::create([
        'vehicle_id' => $context['vehicle']->id,
        'lat' => -1.2830,
        'lng' => 36.8160,
        'speed' => 30,
        'recorded_at' => Carbon::parse('2026-08-09 08:20:00'),
    ]);

    VehicleTelemetry::create([
        'vehicle_id' => $context['vehicle']->id,
        'lat' => -1.2810,
        'lng' => 36.8170,
        'speed' => 40,
        'recorded_at' => Carbon::parse('2026-08-09 08:30:00'),
    ]);

    $response = $this->actingAs($context['user'])
        ->getJson(
            "/api/v1/transport/vehicles/{$context['vehicle']->id}/telemetry/history"
        );

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonCount(3, 'data');

    $response->assertJsonPath('data.0.lat', -1.2850)
        ->assertJsonPath('data.0.speed', 20)
        ->assertJsonPath('data.1.lat', -1.2830)
        ->assertJsonPath('data.1.speed', 30)
        ->assertJsonPath('data.2.lat', -1.2810)
        ->assertJsonPath('data.2.speed', 40);
});

test('telemetry history supports a date filter', function () {
    $context = createLiveTrackingDriverContext();

    VehicleTelemetry::create([
        'vehicle_id' => $context['vehicle']->id,
        'lat' => -1.2850,
        'lng' => 36.8150,
        'speed' => 20,
        'recorded_at' => Carbon::parse('2026-08-08 08:10:00'),
    ]);

    VehicleTelemetry::create([
        'vehicle_id' => $context['vehicle']->id,
        'lat' => -1.2830,
        'lng' => 36.8160,
        'speed' => 30,
        'recorded_at' => Carbon::parse('2026-08-09 08:20:00'),
    ]);

    $response = $this->actingAs($context['user'])
        ->getJson(
            "/api/v1/transport/vehicles/{$context['vehicle']->id}/telemetry/history?date=2026-08-09"
        );

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.lat', -1.2830)
        ->assertJsonPath('data.0.speed', 30);
});

test('telemetry history rejects an invalid date filter', function () {
    $context = createLiveTrackingDriverContext();

    $response = $this->actingAs($context['user'])
        ->getJson(
            "/api/v1/transport/vehicles/{$context['vehicle']->id}/telemetry/history?date=not-a-date"
        );

    $response->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Invalid date filter provided.');
});

test('telemetry history cannot access a vehicle from another school', function () {
    $context = createLiveTrackingDriverContext();

    $otherSchool = School::factory()->create();

    $otherVehicle = Vehicle::create([
        'school_id' => $otherSchool->id,
        'registration_number' => 'OTHER-HISTORY-001',
        'make' => 'OtherCo',
        'model' => 'Other X',
        'capacity' => 20,
        'status' => 'active',
    ]);

    VehicleTelemetry::create([
        'vehicle_id' => $otherVehicle->id,
        'lat' => -1.3000,
        'lng' => 36.8300,
        'speed' => 25,
        'recorded_at' => Carbon::parse('2026-08-09 08:00:00'),
    ]);

    $this->actingAs($context['user'])
        ->getJson(
            "/api/v1/transport/vehicles/{$otherVehicle->id}/telemetry/history"
        )
        ->assertStatus(404);
});

test('telemetry entering an active geofence creates an enter event', function () {
    $context = createLiveTrackingDriverContext();

    $geofence = TransportGeofence::create([
        'school_id' => $context['school']->id,
        'transport_route_id' => $context['route']->id,
        'stop_name' => 'Main Stop',
        'stop_type' => 'Pickup',
        'lat' => -1.2825,
        'lng' => 36.8146,
        'radius_meters' => 120,
        'active' => true,
    ]);

    $this->actingAs($context['user'])
        ->postJson(
            "/api/v1/transport/vehicles/{$context['vehicle']->id}/telemetry",
            [
                'lat' => -1.2825,
                'lng' => 36.8146,
                'speed' => 15,
            ]
        )
        ->assertStatus(200);

    $this->assertDatabaseHas('geofence_events', [
        'school_id' => $context['school']->id,
        'transport_route_id' => $context['route']->id,
        'transport_geofence_id' => $geofence->id,
        'vehicle_id' => $context['vehicle']->id,
        'event_type' => 'enter',
    ]);
});

test('repeated telemetry inside a geofence does not create duplicate enter events', function () {
    $context = createLiveTrackingDriverContext();

    $geofence = TransportGeofence::create([
        'school_id' => $context['school']->id,
        'transport_route_id' => $context['route']->id,
        'stop_name' => 'Main Stop',
        'stop_type' => 'Pickup',
        'lat' => -1.2825,
        'lng' => 36.8146,
        'radius_meters' => 120,
        'active' => true,
    ]);

    $payload = [
        'lat' => -1.2825,
        'lng' => 36.8146,
        'speed' => 15,
    ];

    $this->actingAs($context['user'])
        ->postJson(
            "/api/v1/transport/vehicles/{$context['vehicle']->id}/telemetry",
            $payload
        )
        ->assertStatus(200);

    $this->actingAs($context['user'])
        ->postJson(
            "/api/v1/transport/vehicles/{$context['vehicle']->id}/telemetry",
            $payload
        )
        ->assertStatus(200);

    expect(
        GeofenceEvent::where('transport_geofence_id', $geofence->id)
            ->where('vehicle_id', $context['vehicle']->id)
            ->where('event_type', 'enter')
            ->count()
    )->toBe(1);
});

test('telemetry leaving a geofence creates an exit event', function () {
    $context = createLiveTrackingDriverContext();

    $geofence = TransportGeofence::create([
        'school_id' => $context['school']->id,
        'transport_route_id' => $context['route']->id,
        'stop_name' => 'Main Stop',
        'stop_type' => 'Pickup',
        'lat' => -1.2825,
        'lng' => 36.8146,
        'radius_meters' => 120,
        'active' => true,
    ]);

    $this->actingAs($context['user'])
        ->postJson(
            "/api/v1/transport/vehicles/{$context['vehicle']->id}/telemetry",
            [
                'lat' => -1.2825,
                'lng' => 36.8146,
                'speed' => 10,
            ]
        )
        ->assertStatus(200);

    $this->actingAs($context['user'])
        ->postJson(
            "/api/v1/transport/vehicles/{$context['vehicle']->id}/telemetry",
            [
                'lat' => -1.2900,
                'lng' => 36.8250,
                'speed' => 25,
            ]
        )
        ->assertStatus(200);

    $this->assertDatabaseHas('geofence_events', [
        'school_id' => $context['school']->id,
        'transport_route_id' => $context['route']->id,
        'transport_geofence_id' => $geofence->id,
        'vehicle_id' => $context['vehicle']->id,
        'event_type' => 'exit',
    ]);
});

test('driver can retrieve geofence events for their assigned route', function () {
    $context = createLiveTrackingDriverContext();
    $geofence = TransportGeofence::create([
        'school_id' => $context['school']->id,
        'transport_route_id' => $context['route']->id,
        'stop_name' => 'Main Stop',
        'stop_type' => 'Pickup',
        'lat' => -1.2825,
        'lng' => 36.8146,
        'radius_meters' => 120,
        'active' => true,
    ]);

    GeofenceEvent::create([
        'school_id' => $context['school']->id,
        'transport_route_id' => $context['route']->id,
        'transport_geofence_id' => $geofence->id,
        'vehicle_id' => $context['vehicle']->id,
        'event_type' => 'enter',
        'lat' => -1.2900,
        'lng' => 36.8250,
        'triggered_at' => Carbon::parse('2026-08-09 08:20:00'),
        'payload' => [],
    ]);

    $response = $this->actingAs($context['user'])
        ->getJson('/api/v1/transport/geofence-events/my');

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.transport_route_id', $context['route']->id)
        ->assertJsonPath('data.0.vehicle_id', $context['vehicle']->id)
        ->assertJsonPath('data.0.event_type', 'enter');
});

test('driver geofence events endpoint excludes events from another route', function () {
    $context = createLiveTrackingDriverContext();

    $otherDriverUser = User::factory()->create([
        'school_id' => $context['school']->id,
        'role' => 'driver',
        'status' => 'active',
    ]);

    $otherDriver = Driver::create([
        'user_id' => $otherDriverUser->id,
        'name' => 'Second Driver',
        'phone' => '+1234567893',
        'license_no' => 'DRV-OTHER-002',
        'license_expiry' => Carbon::now()->addYear()->toDateString(),
        'status' => 'active',
    ]);

    $otherVehicle = Vehicle::create([
        'school_id' => $context['school']->id,
        'registration_number' => 'OTHER-ROUTE-001',
        'make' => 'TestCo',
        'model' => 'Other X',
        'capacity' => 20,
        'status' => 'active',
    ]);

    $otherRoute = TransportRoute::create([
        'school_id' => $context['school']->id,
        'name' => 'Other Route',
        'vehicle_id' => $otherVehicle->id,
        'driver_id' => $otherDriver->id,
        'stops' => [
            [
                'name' => 'Other Stop',
                'pickup_time' => '08:00',
                'drop_time' => '17:00',
            ],
        ],
        'monthly_fee' => 0,
    ]);
        $otherGeofence = TransportGeofence::create([
        'school_id' => $context['school']->id,
        'transport_route_id' => $otherRoute->id,
        'stop_name' => 'Other Stop',
        'stop_type' => 'Pickup',
        'lat' => -1.2900,
        'lng' => 36.8250,
        'radius_meters' => 120,
        'active' => true,
    ]);

    GeofenceEvent::create([
    'school_id' => $context['school']->id,
    'transport_route_id' => $otherRoute->id,
    'transport_geofence_id' => $otherGeofence->id,
    'vehicle_id' => $otherVehicle->id,
    'event_type' => 'enter',
    'lat' => -1.2825,
    'lng' => 36.8146,
    'triggered_at' => Carbon::parse('2026-08-09 08:10:00'),
    'payload' => [
        'stop_name' => 'Main Stop',
        'stop_type' => 'Pickup',
    ],
]);

    $response = $this->actingAs($context['user'])
        ->getJson('/api/v1/transport/geofence-events/my');

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonCount(0, 'data');
});

test('non-driver cannot retrieve my geofence events', function () {
    $school = School::factory()->create();

    $user = User::factory()->create([
        'school_id' => $school->id,
        'role' => 'parent',
        'status' => 'active',
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/transport/geofence-events/my')
        ->assertStatus(403)
        ->assertJsonPath('success', false);
});

test('driver with no assigned route receives an empty geofence event list', function () {
    $school = School::factory()->create();

    $user = User::factory()->create([
        'school_id' => $school->id,
        'role' => 'driver',
        'status' => 'active',
    ]);

    Driver::create([
        'user_id' => $user->id,
        'name' => 'Unassigned Driver',
        'phone' => '+1234567894',
        'license_no' => 'DRV-UNASSIGNED-001',
        'license_expiry' => Carbon::now()->addYear()->toDateString(),
        'status' => 'active',
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/transport/geofence-events/my')
        ->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonCount(0, 'data');
});

test('active trip receives a TripLocation when telemetry is submitted', function () {
    $context = createLiveTrackingDriverContext();

    $trip = TransportTrip::create([
        'school_id' => $context['school']->id,
        'transport_route_id' => $context['route']->id,
        'vehicle_id' => $context['vehicle']->id,
        'driver_id' => $context['driver']->id,
        'direction' => 'morning',
        'scheduled_start' => Carbon::parse('2026-08-10 07:00:00'),
        'actual_start' => Carbon::parse('2026-08-10 07:05:00'),
        'start_time' => Carbon::parse('2026-08-10 07:05:00'),
        'status' => TransportTrip::STATUS_IN_PROGRESS,
    ]);

    $this->actingAs($context['user'])
        ->postJson(
            "/api/v1/transport/vehicles/{$context['vehicle']->id}/telemetry",
            [
                'lat' => -1.2825,
                'lng' => 36.8146,
                'speed' => 35,
                'heading' => 127.5,
                'accuracy' => 8.4,
            ]
        )
        ->assertStatus(200);

    $this->assertDatabaseHas('trip_locations', [
        'transport_trip_id' => $trip->id,
        'lat' => -1.2825,
        'lng' => 36.8146,
        'speed' => 35,
    ]);
});


test('trip location contains lat lng speed heading and accuracy', function () {
    $context = createLiveTrackingDriverContext();

    $trip = TransportTrip::create([
        'school_id' => $context['school']->id,
        'transport_route_id' => $context['route']->id,
        'vehicle_id' => $context['vehicle']->id,
        'driver_id' => $context['driver']->id,
        'direction' => 'morning',
        'status' => TransportTrip::STATUS_IN_PROGRESS,
    ]);

    $this->actingAs($context['user'])
        ->postJson(
            "/api/v1/transport/vehicles/{$context['vehicle']->id}/telemetry",
            [
                'lat' => -1.2831,
                'lng' => 36.8152,
                'speed' => 42,
                'heading' => 181.75,
                'accuracy' => 6.25,
            ]
        )
        ->assertStatus(200);

    $location = TripLocation::where('transport_trip_id', $trip->id)->first();

    expect($location)->not->toBeNull()
        ->and((float) $location->lat)->toBe(-1.2831)
        ->and((float) $location->lng)->toBe(36.8152)
        ->and((int) $location->speed)->toBe(42)
        ->and((float) $location->heading)->toBe(181.75)
        ->and((float) $location->accuracy)->toBe(6.25)
        ->and($location->recorded_at)->not->toBeNull();
});


test('scheduled trip does not receive locations', function () {
    $context = createLiveTrackingDriverContext();

    $trip = TransportTrip::create([
        'school_id' => $context['school']->id,
        'transport_route_id' => $context['route']->id,
        'vehicle_id' => $context['vehicle']->id,
        'driver_id' => $context['driver']->id,
        'direction' => 'morning',
        'scheduled_start' => Carbon::parse('2026-08-10 07:00:00'),
        'status' => TransportTrip::STATUS_SCHEDULED,
    ]);

    $this->actingAs($context['user'])
        ->postJson(
            "/api/v1/transport/vehicles/{$context['vehicle']->id}/telemetry",
            [
                'lat' => -1.2825,
                'lng' => 36.8146,
                'speed' => 20,
                'heading' => 90,
                'accuracy' => 10,
            ]
        )
        ->assertStatus(200);

    $this->assertDatabaseMissing('trip_locations', [
        'transport_trip_id' => $trip->id,
    ]);
});


test('completed trip does not receive locations', function () {
    $context = createLiveTrackingDriverContext();

    $trip = TransportTrip::create([
        'school_id' => $context['school']->id,
        'transport_route_id' => $context['route']->id,
        'vehicle_id' => $context['vehicle']->id,
        'driver_id' => $context['driver']->id,
        'direction' => 'morning',
        'scheduled_start' => Carbon::parse('2026-08-10 07:00:00'),
        'actual_start' => Carbon::parse('2026-08-10 07:05:00'),
        'actual_end' => Carbon::parse('2026-08-10 08:30:00'),
        'start_time' => Carbon::parse('2026-08-10 07:05:00'),
        'end_time' => Carbon::parse('2026-08-10 08:30:00'),
        'status' => TransportTrip::STATUS_COMPLETED,
    ]);

    $this->actingAs($context['user'])
        ->postJson(
            "/api/v1/transport/vehicles/{$context['vehicle']->id}/telemetry",
            [
                'lat' => -1.2900,
                'lng' => 36.8250,
                'speed' => 25,
                'heading' => 45,
                'accuracy' => 12,
            ]
        )
        ->assertStatus(200);

    $this->assertDatabaseMissing('trip_locations', [
        'transport_trip_id' => $trip->id,
    ]);
});


test('telemetry still updates vehicle state regardless of trip status', function () {
    $context = createLiveTrackingDriverContext();

    TransportTrip::create([
        'school_id' => $context['school']->id,
        'transport_route_id' => $context['route']->id,
        'vehicle_id' => $context['vehicle']->id,
        'driver_id' => $context['driver']->id,
        'direction' => 'morning',
        'status' => TransportTrip::STATUS_COMPLETED,
    ]);

    $this->actingAs($context['user'])
        ->postJson(
            "/api/v1/transport/vehicles/{$context['vehicle']->id}/telemetry",
            [
                'lat' => -1.2910,
                'lng' => 36.8260,
                'speed' => 30,
                'heading' => 270,
                'accuracy' => 7.5,
            ]
        )
        ->assertStatus(200);

    $context['vehicle']->refresh();

    expect((float) $context['vehicle']->last_lat)->toBe(-1.2910)
        ->and((float) $context['vehicle']->last_lng)->toBe(36.8260)
        ->and((int) $context['vehicle']->last_speed)->toBe(30)
        ->and($context['vehicle']->location_updated_at)->not->toBeNull();
});


test('driver cannot write to another drivers active trip', function () {
    $context = createLiveTrackingDriverContext();

    $otherDriverUser = User::factory()->create([
        'school_id' => $context['school']->id,
        'role' => 'driver',
        'status' => 'active',
    ]);

    $otherDriver = Driver::create([
        'user_id' => $otherDriverUser->id,
        'name' => 'Another Active Driver',
        'phone' => '+1234567894',
        'license_no' => 'DRV-ACTIVE-003',
        'license_expiry' => Carbon::now()->addYear()->toDateString(),
        'status' => 'active',
    ]);

    $otherVehicle = Vehicle::create([
        'school_id' => $context['school']->id,
        'registration_number' => 'OTHER-ACTIVE-001',
        'make' => 'TestCo',
        'model' => 'Active X',
        'capacity' => 20,
        'status' => 'active',
    ]);

    $otherRoute = TransportRoute::create([
        'school_id' => $context['school']->id,
        'name' => 'Other Active Route',
        'vehicle_id' => $otherVehicle->id,
        'driver_id' => $otherDriver->id,
        'stops' => [
            [
                'name' => 'Other Stop',
                'pickup_time' => '08:00',
                'drop_time' => '17:00',
            ],
        ],
        'monthly_fee' => 0,
    ]);

    $otherTrip = TransportTrip::create([
        'school_id' => $context['school']->id,
        'transport_route_id' => $otherRoute->id,
        'vehicle_id' => $otherVehicle->id,
        'driver_id' => $otherDriver->id,
        'direction' => 'morning',
        'status' => TransportTrip::STATUS_IN_PROGRESS,
    ]);

    $response = $this->actingAs($context['user'])
        ->postJson(
            "/api/v1/transport/vehicles/{$otherVehicle->id}/telemetry",
            [
                'lat' => -1.2900,
                'lng' => 36.8250,
                'speed' => 40,
                'heading' => 180,
                'accuracy' => 5,
            ]
        );

    $response->assertStatus(403)
        ->assertJsonPath('success', false);

    $this->assertDatabaseMissing('trip_locations', [
        'transport_trip_id' => $otherTrip->id,
    ]);
});


test('trip location remains school scoped', function () {
    $context = createLiveTrackingDriverContext();

    $otherSchool = School::factory()->create();

    $otherUser = User::factory()->create([
        'school_id' => $otherSchool->id,
        'role' => 'driver',
        'status' => 'active',
    ]);

    $otherDriver = Driver::create([
        'user_id' => $otherUser->id,
        'name' => 'Other School Driver',
        'phone' => '+1234567895',
        'license_no' => 'DRV-SCHOOL-004',
        'license_expiry' => Carbon::now()->addYear()->toDateString(),
        'status' => 'active',
    ]);

    $otherVehicle = Vehicle::create([
        'school_id' => $otherSchool->id,
        'registration_number' => 'OTHER-SCHOOL-001',
        'make' => 'TestCo',
        'model' => 'School X',
        'capacity' => 20,
        'status' => 'active',
    ]);

    $otherRoute = TransportRoute::create([
        'school_id' => $otherSchool->id,
        'name' => 'Other School Route',
        'vehicle_id' => $otherVehicle->id,
        'driver_id' => $otherDriver->id,
        'stops' => [
            [
                'name' => 'Other School Stop',
                'pickup_time' => '08:00',
                'drop_time' => '17:00',
            ],
        ],
        'monthly_fee' => 0,
    ]);

    $otherTrip = TransportTrip::create([
        'school_id' => $otherSchool->id,
        'transport_route_id' => $otherRoute->id,
        'vehicle_id' => $otherVehicle->id,
        'driver_id' => $otherDriver->id,
        'direction' => 'morning',
        'status' => TransportTrip::STATUS_IN_PROGRESS,
    ]);

    $response = $this->actingAs($context['user'])
        ->postJson(
            "/api/v1/transport/vehicles/{$otherVehicle->id}/telemetry",
            [
                'lat' => -1.3000,
                'lng' => 36.8300,
                'speed' => 35,
                'heading' => 90,
                'accuracy' => 9,
            ]
        );

    $response->assertStatus(403)
        ->assertJsonPath('success', false);

    $this->assertDatabaseMissing('trip_locations', [
        'transport_trip_id' => $otherTrip->id,
    ]);
});

test('driver can retrieve locations for their trip', function () {
    $context = createLiveTrackingDriverContext();

    $trip = TransportTrip::create([
        'school_id' => $context['school']->id,
        'transport_route_id' => $context['route']->id,
        'vehicle_id' => $context['vehicle']->id,
        'driver_id' => $context['driver']->id,
        'direction' => 'morning',
        'scheduled_start' => Carbon::now()->subMinutes(30),
        'status' => TransportTrip::STATUS_IN_PROGRESS,
        'actual_start' => Carbon::now()->subMinutes(20),
        'start_time' => Carbon::now()->subMinutes(20),
    ]);

    TripLocation::create([
        'transport_trip_id' => $trip->id,
        'lat' => -1.2825,
        'lng' => 36.8146,
        'speed' => 35,
        'heading' => 180.00,
        'accuracy' => 8.50,
        'recorded_at' => Carbon::parse('2026-08-10 08:00:00'),
    ]);

    $response = $this->actingAs($context['user'])
        ->getJson("/api/v1/transport/trips/{$trip->id}/locations");

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.transport_trip_id', $trip->id)
        ->assertJsonPath('data.0.lat', -1.2825)
        ->assertJsonPath('data.0.lng', 36.8146)
        ->assertJsonPath('data.0.speed', 35)
        ->assertJsonPath('data.0.heading', 180)
        ->assertJsonPath('data.0.accuracy', 8.5);
});


test('trip locations are returned in chronological order', function () {
    $context = createLiveTrackingDriverContext();

    $trip = TransportTrip::create([
        'school_id' => $context['school']->id,
        'transport_route_id' => $context['route']->id,
        'vehicle_id' => $context['vehicle']->id,
        'driver_id' => $context['driver']->id,
        'direction' => 'morning',
        'scheduled_start' => Carbon::now()->subMinutes(30),
        'status' => TransportTrip::STATUS_IN_PROGRESS,
        'actual_start' => Carbon::now()->subMinutes(20),
        'start_time' => Carbon::now()->subMinutes(20),
    ]);

    TripLocation::create([
        'transport_trip_id' => $trip->id,
        'lat' => -1.2840,
        'lng' => 36.8160,
        'speed' => 25,
        'heading' => 170,
        'accuracy' => 10,
        'recorded_at' => Carbon::parse('2026-08-10 08:02:00'),
    ]);

    TripLocation::create([
        'transport_trip_id' => $trip->id,
        'lat' => -1.2825,
        'lng' => 36.8146,
        'speed' => 35,
        'heading' => 180,
        'accuracy' => 8,
        'recorded_at' => Carbon::parse('2026-08-10 08:01:00'),
    ]);

    $response = $this->actingAs($context['user'])
        ->getJson("/api/v1/transport/trips/{$trip->id}/locations");

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonCount(2, 'data');

    expect($response->json('data.0.recorded_at'))
        ->toBe('2026-08-10T08:02:00.000000Z');

    expect($response->json('data.1.recorded_at'))
        ->toBe('2026-08-10T08:01:00.000000Z');
});


test('trip location retrieval is school scoped', function () {
    $context = createLiveTrackingDriverContext();

    $otherSchool = School::factory()->create();

    $otherUser = User::factory()->create([
        'school_id' => $otherSchool->id,
        'role' => 'driver',
        'status' => 'active',
    ]);

    $otherDriver = Driver::create([
        'user_id' => $otherUser->id,
        'name' => 'Other School Driver',
        'phone' => '+1234567894',
        'license_no' => 'DRV-OTHER-SCHOOL-001',
        'license_expiry' => Carbon::now()->addYear()->toDateString(),
        'status' => 'active',
    ]);

    $otherVehicle = Vehicle::create([
        'school_id' => $otherSchool->id,
        'registration_number' => 'OTHER-SCHOOL-001',
        'make' => 'TestCo',
        'model' => 'Other X',
        'capacity' => 20,
        'status' => 'active',
    ]);

    $otherRoute = TransportRoute::create([
        'school_id' => $otherSchool->id,
        'name' => 'Other School Route',
        'vehicle_id' => $otherVehicle->id,
        'driver_id' => $otherDriver->id,
        'stops' => [],
        'monthly_fee' => 0,
    ]);

    $otherTrip = TransportTrip::create([
        'school_id' => $otherSchool->id,
        'transport_route_id' => $otherRoute->id,
        'vehicle_id' => $otherVehicle->id,
        'driver_id' => $otherDriver->id,
        'direction' => 'morning',
        'scheduled_start' => Carbon::now(),
        'status' => TransportTrip::STATUS_IN_PROGRESS,
    ]);

    TripLocation::create([
        'transport_trip_id' => $otherTrip->id,
        'lat' => -1.2900,
        'lng' => 36.8250,
        'speed' => 20,
        'heading' => 90,
        'accuracy' => 12,
        'recorded_at' => Carbon::now(),
    ]);

    $this->actingAs($context['user'])
        ->getJson("/api/v1/transport/trips/{$otherTrip->id}/locations")
        ->assertStatus(404);
});


test('trip location retrieval returns an empty list when trip has no locations', function () {
    $context = createLiveTrackingDriverContext();

    $trip = TransportTrip::create([
        'school_id' => $context['school']->id,
        'transport_route_id' => $context['route']->id,
        'vehicle_id' => $context['vehicle']->id,
        'driver_id' => $context['driver']->id,
        'direction' => 'morning',
        'scheduled_start' => Carbon::now(),
        'status' => TransportTrip::STATUS_SCHEDULED,
    ]);

    $response = $this->actingAs($context['user'])
        ->getJson("/api/v1/transport/trips/{$trip->id}/locations");

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonCount(0, 'data');
});
