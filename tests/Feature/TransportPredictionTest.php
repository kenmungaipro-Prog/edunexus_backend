<?php

use App\Models\Driver;
use App\Models\GeofenceEvent;
use App\Models\School;
use App\Models\Transport\TransportRoute;
use App\Models\Transport\TransportGeofence;
use App\Models\Vehicle;
use App\Models\VehicleTelemetry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('driver receives transport prediction for assigned route', function () {
    $school = School::factory()->create();

    $user = User::factory()->create([
        'school_id' => $school->id,
        'role' => 'driver',
        'status' => 'active',
    ]);

    $driver = Driver::create([
        'user_id' => $user->id,
        'name' => 'Test Driver',
        'phone' => '+1234567890',
        'license_no' => 'DRV-12345',
        'license_expiry' => Carbon::now()->addYear()->toDateString(),
        'status' => 'active',
    ]);

    $vehicle = Vehicle::create([
        'registration_number' => 'ABC-1234',
        'make' => 'TestCo',
        'model' => 'Model X',
        'capacity' => 24,
        'status' => 'active',
    ]);

    $route = TransportRoute::create([
        'school_id' => $school->id,
        'name' => 'Route 1',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'stops' => [
            [
                'name' => 'Main Stop',
                'pickup_time' => '2026-07-29 08:00:00',
                'drop_time' => '2026-07-29 17:00:00',
            ],
        ],
        'monthly_fee' => 0,
    ]);

    VehicleTelemetry::create([
        'vehicle_id' => $vehicle->id,
        'lat' => -1.2800,
        'lng' => 36.8167,
        'speed' => 10,
        'recorded_at' => Carbon::parse('2026-07-29 07:50:00'),
    ]);

    VehicleTelemetry::create([
        'vehicle_id' => $vehicle->id,
        'lat' => -1.2810,
        'lng' => 36.8170,
        'speed' => 0,
        'recorded_at' => Carbon::parse('2026-07-29 07:55:00'),
    ]);

    VehicleTelemetry::create([
        'vehicle_id' => $vehicle->id,
        'lat' => -1.2820,
        'lng' => 36.8180,
        'speed' => 0,
        'recorded_at' => Carbon::parse('2026-07-29 08:00:00'),
    ]);

    $geofence = TransportGeofence::create([
        'school_id' => $school->id,
        'transport_route_id' => $route->id,
        'stop_name' => 'Main Stop',
        'stop_type' => 'Pickup',
        'lat' => -1.2820,
        'lng' => 36.8180,
        'radius_meters' => 120,
        'active' => true,
    ]);

    GeofenceEvent::create([
        'school_id' => $school->id,
        'transport_route_id' => $route->id,
        'transport_geofence_id' => $geofence->id,
        'vehicle_id' => $vehicle->id,
        'event_type' => 'enter',
        'lat' => -1.2820,
        'lng' => 36.8180,
        'triggered_at' => Carbon::parse('2026-07-29 08:10:00'),
        'payload' => [
            'stop_name' => 'Main Stop',
            'stop_type' => 'Pickup',
        ],
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/transport/analytics/prediction?date=2026-07-29');

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.route_id', $route->id)
        ->assertJsonPath('data.status', 'Minor delay')
        ->assertJsonPath('data.predicted_delay_minutes', 15)
        ->assertJsonPath('data.basis.average_speed', 3.3)
        ->assertJsonPath('data.basis.stop_delay', 10);
});

test('driver prediction endpoint rejects non-driver users', function () {
    $school = School::factory()->create();

    $user = User::factory()->create([
        'school_id' => $school->id,
        'role' => 'parent',
        'status' => 'active',
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/transport/analytics/prediction')
        ->assertStatus(403)
        ->assertJsonPath('success', false);
});
