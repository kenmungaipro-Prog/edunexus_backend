<?php

use App\Events\GeofenceTriggered;
use App\Events\VehicleLocationUpdated;
use App\Jobs\SendSmsJob;
use App\Models\Driver;
use App\Models\School;
use App\Models\Transport\TransportGeofence;
use App\Models\Transport\TransportRoute;
use App\Models\Transport\TransportTrip;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function createTransportDemoContext(): array
{
    $school = School::factory()->create();
    $admin = User::factory()->create([
        'school_id' => $school->id,
        'role' => 'admin',
        'status' => 'active',
    ]);
    $driverUser = User::factory()->create([
        'school_id' => $school->id,
        'role' => 'driver',
        'status' => 'active',
    ]);
    $driver = Driver::create([
        'user_id' => $driverUser->id,
        'name' => 'Demo Test Driver',
        'phone' => '+1234567800',
        'license_no' => 'DRV-DEMO-001',
        'license_expiry' => now()->addYear()->toDateString(),
        'status' => 'active',
    ]);
    $vehicle = Vehicle::create([
        'school_id' => $school->id,
        'registration_number' => 'DEMO-001',
        'make' => 'TestCo',
        'model' => 'Demo X',
        'capacity' => 24,
        'status' => 'active',
        'last_lat' => -1.3001,
        'last_lng' => 36.8001,
        'last_speed' => 0,
        'location_updated_at' => now()->subHour(),
    ]);
    $route = TransportRoute::create([
        'school_id' => $school->id,
        'name' => 'Demo Test Route',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'stops' => [['name' => 'Demo Stop', 'pickup_time' => '08:00', 'drop_time' => '17:00']],
        'monthly_fee' => 0,
    ]);
    config(['transport.demo_vehicle_id' => $vehicle->id]);

    return compact('school', 'admin', 'driverUser', 'driver', 'vehicle', 'route');
}

function startTransportDemo($test, array $context): array
{
    $response = $test->actingAs($context['admin'])
        ->postJson("/api/v1/transport/vehicles/{$context['vehicle']->id}/demo-session/start");

    $response->assertCreated()->assertJsonPath('success', true);

    return $response->json('data');
}

test('demo GPS is isolated from genuine vehicle telemetry trips and alerts', function () {
    Event::fake([VehicleLocationUpdated::class, GeofenceTriggered::class]);
    Bus::fake();
    $context = createTransportDemoContext();
    $trip = TransportTrip::create([
        'school_id' => $context['school']->id,
        'transport_route_id' => $context['route']->id,
        'vehicle_id' => $context['vehicle']->id,
        'driver_id' => $context['driver']->id,
        'direction' => 'morning',
        'status' => TransportTrip::STATUS_IN_PROGRESS,
        'start_time' => now()->subMinutes(10),
    ]);
    TransportGeofence::create([
        'school_id' => $context['school']->id,
        'transport_route_id' => $context['route']->id,
        'stop_name' => 'Demo Stop',
        'stop_type' => 'pickup',
        'lat' => -1.2825,
        'lng' => 36.8146,
        'radius_meters' => 100,
        'active' => true,
    ]);

    $session = startTransportDemo($this, $context);
    $this->actingAs($context['admin'])
        ->postJson("/api/v1/transport/vehicles/{$context['vehicle']->id}/demo-session/position", [
            'session_id' => $session['session_id'],
            'lat' => -1.2825,
            'lng' => 36.8146,
            'speed' => 24,
            'heading' => 90,
            'accuracy' => 5,
            'timestamp' => now()->toIso8601String(),
        ])
        ->assertOk()
        ->assertJsonPath('data.lat', -1.2825)
        ->assertJsonPath('data.lng', 36.8146);

    $context['vehicle']->refresh();
    expect((float) $context['vehicle']->last_lat)->toBe(-1.3001)
        ->and((float) $context['vehicle']->last_lng)->toBe(36.8001);

    $this->assertDatabaseCount('vehicle_telemetry', 0);
    $this->assertDatabaseCount('trip_locations', 0);
    $this->assertDatabaseCount('geofence_events', 0);
    Event::assertNotDispatched(VehicleLocationUpdated::class);
    Event::assertNotDispatched(GeofenceTriggered::class);
    Bus::assertNotDispatched(SendSmsJob::class);
    $this->assertDatabaseHas('transport_trips', ['id' => $trip->id, 'status' => TransportTrip::STATUS_IN_PROGRESS]);

    $this->actingAs($context['admin'])
        ->getJson('/api/v1/transport/live')
        ->assertOk()
        ->assertJsonPath('data.0.lat', -1.3001)
        ->assertJsonPath('data.0.demo.lat', -1.2825)
        ->assertJsonPath('data.0.demo.lng', 36.8146);

    config(['transport.dispatch_phone' => '+1234567899']);
    $this->actingAs($context['admin'])
        ->postJson("/api/v1/transport/vehicles/{$context['vehicle']->id}/demo-session/position", [
            'session_id' => $session['session_id'],
            'lat' => -1.5,
            'lng' => 36.5,
            'speed' => 18,
            'timestamp' => now()->addSecond()->toIso8601String(),
        ])
        ->assertOk();

    $this->assertDatabaseCount('vehicle_telemetry', 0);
    $this->assertDatabaseCount('trip_locations', 0);
    $this->assertDatabaseCount('geofence_events', 0);
    Bus::assertNotDispatched(SendSmsJob::class);
});

test('stopping a demo removes the overlay and preserves the real vehicle position', function () {
    $context = createTransportDemoContext();
    $session = startTransportDemo($this, $context);
    $this->actingAs($context['admin'])
        ->postJson("/api/v1/transport/vehicles/{$context['vehicle']->id}/demo-session/position", [
            'session_id' => $session['session_id'],
            'lat' => -1.2825,
            'lng' => 36.8146,
            'timestamp' => now()->toIso8601String(),
        ])
        ->assertOk();

    $this->actingAs($context['admin'])
        ->postJson("/api/v1/transport/vehicles/{$context['vehicle']->id}/demo-session/stop", [
            'session_id' => $session['session_id'],
        ])
        ->assertOk();

    $this->assertDatabaseCount('transport_demo_sessions', 0);
    $context['vehicle']->refresh();
    expect((float) $context['vehicle']->last_lat)->toBe(-1.3001)
        ->and((float) $context['vehicle']->last_lng)->toBe(36.8001);

    $this->actingAs($context['admin'])
        ->getJson('/api/v1/transport/live')
        ->assertOk()
        ->assertJsonPath('data.0.demo', null);
});

test('demo controls are restricted to administrators and the current school', function () {
    $context = createTransportDemoContext();

    $this->actingAs($context['driverUser'])
        ->postJson("/api/v1/transport/vehicles/{$context['vehicle']->id}/demo-session/start")
        ->assertForbidden();

    $otherSchool = School::factory()->create();
    $otherVehicle = Vehicle::create([
        'school_id' => $otherSchool->id,
        'registration_number' => 'DEMO-OTHER',
        'make' => 'TestCo',
        'model' => 'Demo X',
        'capacity' => 24,
        'status' => 'active',
    ]);

    $this->actingAs($context['admin'])
        ->postJson("/api/v1/transport/vehicles/{$otherVehicle->id}/demo-session/start")
        ->assertNotFound();

    $this->assertDatabaseCount('transport_demo_sessions', 0);
});

test('demo target is fail-closed and limited to the configured vehicle', function () {
    $context = createTransportDemoContext();

    $this->actingAs($context['admin'])
        ->getJson('/api/v1/transport/demo-target')
        ->assertOk()
        ->assertJsonPath('data.vehicle_id', $context['vehicle']->id);

    $otherVehicle = Vehicle::create([
        'school_id' => $context['school']->id,
        'registration_number' => 'DEMO-OTHER',
        'make' => 'TestCo',
        'model' => 'Demo X',
        'capacity' => 24,
        'status' => 'active',
    ]);

    $this->actingAs($context['admin'])
        ->postJson("/api/v1/transport/vehicles/{$otherVehicle->id}/demo-session/start")
        ->assertNotFound();

    config(['transport.demo_vehicle_id' => null]);
    $this->actingAs($context['admin'])
        ->getJson('/api/v1/transport/demo-target')
        ->assertStatus(503);
    $this->actingAs($context['admin'])
        ->postJson("/api/v1/transport/vehicles/{$context['vehicle']->id}/demo-session/start")
        ->assertNotFound();
});

test('a school can have only one active demo session at a time', function () {
    $context = createTransportDemoContext();
    startTransportDemo($this, $context);

    $this->actingAs($context['admin'])
        ->postJson("/api/v1/transport/vehicles/{$context['vehicle']->id}/demo-session/start")
        ->assertStatus(409);

    $this->assertDatabaseCount('transport_demo_sessions', 1);
});

test('demo position updates reject out-of-order fixes without renewing the lease', function () {
    $context = createTransportDemoContext();
    $session = startTransportDemo($this, $context);
    $newerFix = now()->subSeconds(2);

    $this->actingAs($context['admin'])
        ->postJson("/api/v1/transport/vehicles/{$context['vehicle']->id}/demo-session/position", [
            'session_id' => $session['session_id'],
            'lat' => -1.2825,
            'lng' => 36.8146,
            'timestamp' => $newerFix->toIso8601String(),
        ])
        ->assertOk();

    $expiryAfterValidFix = DB::table('transport_demo_sessions')
        ->where('session_id', $session['session_id'])
        ->value('expires_at');
    $this->actingAs($context['admin'])
        ->postJson("/api/v1/transport/vehicles/{$context['vehicle']->id}/demo-session/position", [
            'session_id' => $session['session_id'],
            'lat' => -1.5,
            'lng' => 36.5,
            'timestamp' => $newerFix->copy()->subSecond()->toIso8601String(),
        ])
        ->assertUnprocessable();

    $this->assertDatabaseHas('transport_demo_sessions', [
        'session_id' => $session['session_id'],
        'lat' => -1.2825,
        'lng' => 36.8146,
        'expires_at' => $expiryAfterValidFix,
    ]);
});

test('demo session expires after no GPS fix and cannot be refreshed by a stale fix', function () {
    $context = createTransportDemoContext();
    $session = startTransportDemo($this, $context);

    $this->actingAs($context['admin'])
        ->postJson("/api/v1/transport/vehicles/{$context['vehicle']->id}/demo-session/position", [
            'session_id' => $session['session_id'],
            'lat' => -1.2825,
            'lng' => 36.8146,
            'timestamp' => now()->subSeconds(31)->toIso8601String(),
        ])
        ->assertUnprocessable();

    $this->travel(31)->seconds();

    $this->actingAs($context['admin'])
        ->getJson('/api/v1/transport/demo-session')
        ->assertOk()
        ->assertJsonPath('data', null);

    $this->assertDatabaseCount('transport_demo_sessions', 0);
});

test('demo session has a hard 15-minute lifetime', function () {
    $context = createTransportDemoContext();
    $session = startTransportDemo($this, $context);

    $this->travel(16)->minutes();

    $this->actingAs($context['admin'])
        ->postJson("/api/v1/transport/vehicles/{$context['vehicle']->id}/demo-session/position", [
            'session_id' => $session['session_id'],
            'lat' => -1.2825,
            'lng' => 36.8146,
            'timestamp' => now()->toIso8601String(),
        ])
        ->assertStatus(410);

    $this->assertDatabaseCount('vehicle_telemetry', 0);
    $this->assertDatabaseCount('trip_locations', 0);
});
