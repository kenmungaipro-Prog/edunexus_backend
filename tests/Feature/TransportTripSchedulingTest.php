<?php

use App\Models\Driver;
use App\Models\School;
use App\Models\Transport\TransportRoute;
use App\Models\Transport\TransportTrip;
use App\Models\Vehicle;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('admin can schedule a transport trip', function () {
    $school = School::factory()->create();
    $user = User::factory()->create([
        'school_id' => $school->id,
        'role' => 'admin',
        'status' => 'active',
    ]);

    $vehicle = Vehicle::create([
        'school_id' => $school->id,
        'registration_number' => 'BUS-200',
        'make' => 'Coach',
        'model' => 'Alpha',
        'capacity' => 40,
        'status' => 'active',
    ]);

    $driver = Driver::create([
        'school_id' => $school->id,
        'name' => 'Driver D',
        'phone' => '+254700000010',
        'license_no' => 'LIC-010',
        'license_expiry' => Carbon::now()->addYear()->toDateString(),
        'status' => 'active',
    ]);

    $route = TransportRoute::create([
        'school_id' => $school->id,
        'name' => 'Route 10',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'stops' => [],
        'monthly_fee' => 6000,
    ]);

    $scheduledStart = Carbon::now()->addDay()->toIso8601String();

    $response = $this->actingAs($user)
        ->postJson("/api/v1/transport/routes/{$route->id}/trips", [
            'direction' => 'morning',
            'scheduled_start' => $scheduledStart,
            'notes' => 'School pickup run',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', TransportTrip::STATUS_SCHEDULED)
        ->assertJsonPath('data.scheduled_start', fn ($value) => \Carbon\Carbon::parse($value)->toIso8601String() === $scheduledStart)
        ->assertJsonPath('data.vehicle_id', $vehicle->id)
        ->assertJsonPath('data.driver_id', $driver->id);
});

test('scheduled trip may be started and completed', function () {
    $school = School::factory()->create();
    $user = User::factory()->create([
        'school_id' => $school->id,
        'role' => 'admin',
        'status' => 'active',
    ]);

    $vehicle = Vehicle::create([
        'school_id' => $school->id,
        'registration_number' => 'BUS-201',
        'make' => 'Coach',
        'model' => 'Beta',
        'capacity' => 40,
        'status' => 'active',
    ]);

    $driver = Driver::create([
        'school_id' => $school->id,
        'name' => 'Driver E',
        'phone' => '+254700000011',
        'license_no' => 'LIC-011',
        'license_expiry' => Carbon::now()->addYear()->toDateString(),
        'status' => 'active',
    ]);

    $route = TransportRoute::create([
        'school_id' => $school->id,
        'name' => 'Route 11',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'stops' => [],
        'monthly_fee' => 6000,
    ]);

    $trip = TransportTrip::create([
        'school_id' => $school->id,
        'transport_route_id' => $route->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'direction' => 'afternoon',
        'scheduled_start' => Carbon::now()->addDay(),
        'status' => TransportTrip::STATUS_SCHEDULED,
    ]);

    $startResponse = $this->actingAs($user)
        ->postJson("/api/v1/transport/trips/{$trip->id}/start");

    $startResponse->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', TransportTrip::STATUS_IN_PROGRESS)
        ->assertJsonPath('data.actual_start', fn ($value) => ! empty($value));

    $endResponse = $this->actingAs($user)
        ->postJson("/api/v1/transport/trips/{$trip->id}/end");

    $endResponse->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', TransportTrip::STATUS_COMPLETED)
        ->assertJsonPath('data.actual_end', fn ($value) => ! empty($value));
});

test('completed trip cannot be started again', function () {
    $school = School::factory()->create();

    $user = User::factory()->create([
        'school_id' => $school->id,
        'role' => 'admin',
        'status' => 'active',
    ]);

    $vehicle = Vehicle::create([
        'school_id' => $school->id,
        'registration_number' => 'BUS-202',
        'make' => 'Coach',
        'model' => 'Gamma',
        'capacity' => 40,
        'status' => 'active',
    ]);

    $driver = Driver::create([
        'school_id' => $school->id,
        'name' => 'Driver F',
        'phone' => '+254700000012',
        'license_no' => 'LIC-012',
        'license_expiry' => Carbon::now()->addYear()->toDateString(),
        'status' => 'active',
    ]);

    $route = TransportRoute::create([
        'school_id' => $school->id,
        'name' => 'Route 12',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'stops' => [],
        'monthly_fee' => 6000,
    ]);

    $trip = TransportTrip::create([
        'school_id' => $school->id,
        'transport_route_id' => $route->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'direction' => 'morning',
        'scheduled_start' => Carbon::now()->addDay(),
        'status' => TransportTrip::STATUS_COMPLETED,
    ]);

    $this->actingAs($user)
        ->postJson("/api/v1/transport/trips/{$trip->id}/start")
        ->assertStatus(422)
        ->assertJsonPath('success', false);
});


test('scheduled trip cannot be completed', function () {
    $school = School::factory()->create();

    $user = User::factory()->create([
        'school_id' => $school->id,
        'role' => 'admin',
        'status' => 'active',
    ]);

    $vehicle = Vehicle::create([
        'school_id' => $school->id,
        'registration_number' => 'BUS-203',
        'make' => 'Coach',
        'model' => 'Delta',
        'capacity' => 40,
        'status' => 'active',
    ]);

    $driver = Driver::create([
        'school_id' => $school->id,
        'name' => 'Driver G',
        'phone' => '+254700000013',
        'license_no' => 'LIC-013',
        'license_expiry' => Carbon::now()->addYear()->toDateString(),
        'status' => 'active',
    ]);

    $route = TransportRoute::create([
        'school_id' => $school->id,
        'name' => 'Route 13',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'stops' => [],
        'monthly_fee' => 6000,
    ]);

    $trip = TransportTrip::create([
        'school_id' => $school->id,
        'transport_route_id' => $route->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'direction' => 'morning',
        'scheduled_start' => Carbon::now()->addDay(),
        'status' => TransportTrip::STATUS_SCHEDULED,
    ]);

    $this->actingAs($user)
        ->postJson("/api/v1/transport/trips/{$trip->id}/end")
        ->assertStatus(422)
        ->assertJsonPath('success', false);
});


test('starting a trip records actual start and start time', function () {
    $school = School::factory()->create();

    $user = User::factory()->create([
        'school_id' => $school->id,
        'role' => 'admin',
        'status' => 'active',
    ]);

    $vehicle = Vehicle::create([
        'school_id' => $school->id,
        'registration_number' => 'BUS-204',
        'make' => 'Coach',
        'model' => 'Epsilon',
        'capacity' => 40,
        'status' => 'active',
    ]);

    $driver = Driver::create([
        'school_id' => $school->id,
        'name' => 'Driver H',
        'phone' => '+254700000014',
        'license_no' => 'LIC-014',
        'license_expiry' => Carbon::now()->addYear()->toDateString(),
        'status' => 'active',
    ]);

    $route = TransportRoute::create([
        'school_id' => $school->id,
        'name' => 'Route 14',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'stops' => [],
        'monthly_fee' => 6000,
    ]);

    $trip = TransportTrip::create([
        'school_id' => $school->id,
        'transport_route_id' => $route->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'direction' => 'morning',
        'scheduled_start' => Carbon::now()->addDay(),
        'status' => TransportTrip::STATUS_SCHEDULED,
    ]);

    $this->actingAs($user)
        ->postJson("/api/v1/transport/trips/{$trip->id}/start")
        ->assertStatus(200)
        ->assertJsonPath('data.status', TransportTrip::STATUS_IN_PROGRESS)
        ->assertJsonPath('data.actual_start', fn ($value) => ! empty($value))
        ->assertJsonPath('data.start_time', fn ($value) => ! empty($value));

    $trip->refresh();

    expect($trip->actual_start)->not->toBeNull()
        ->and($trip->start_time)->not->toBeNull();
});


test('completing a trip records actual end and end time', function () {
    $school = School::factory()->create();

    $user = User::factory()->create([
        'school_id' => $school->id,
        'role' => 'admin',
        'status' => 'active',
    ]);

    $vehicle = Vehicle::create([
        'school_id' => $school->id,
        'registration_number' => 'BUS-205',
        'make' => 'Coach',
        'model' => 'Zeta',
        'capacity' => 40,
        'status' => 'active',
    ]);

    $driver = Driver::create([
        'school_id' => $school->id,
        'name' => 'Driver I',
        'phone' => '+254700000015',
        'license_no' => 'LIC-015',
        'license_expiry' => Carbon::now()->addYear()->toDateString(),
        'status' => 'active',
    ]);

    $route = TransportRoute::create([
        'school_id' => $school->id,
        'name' => 'Route 15',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'stops' => [],
        'monthly_fee' => 6000,
    ]);

    $trip = TransportTrip::create([
        'school_id' => $school->id,
        'transport_route_id' => $route->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'direction' => 'morning',
        'scheduled_start' => Carbon::now()->addDay(),
        'status' => TransportTrip::STATUS_IN_PROGRESS,
    ]);

    $this->actingAs($user)
        ->postJson("/api/v1/transport/trips/{$trip->id}/end")
        ->assertStatus(200)
        ->assertJsonPath('data.status', TransportTrip::STATUS_COMPLETED)
        ->assertJsonPath('data.actual_end', fn ($value) => ! empty($value))
        ->assertJsonPath('data.end_time', fn ($value) => ! empty($value));

    $trip->refresh();

    expect($trip->actual_end)->not->toBeNull()
        ->and($trip->end_time)->not->toBeNull();
});

test('assigned driver can start their own scheduled trip', function () {
    $school = School::factory()->create();

    $driverUser = User::factory()->create([
        'school_id' => $school->id,
        'role' => 'driver',
        'status' => 'active',
    ]);

    $vehicle = Vehicle::create([
        'school_id' => $school->id,
        'registration_number' => 'BUS-210',
        'make' => 'Coach',
        'model' => 'Gamma',
        'capacity' => 40,
        'status' => 'active',
    ]);

    $driver = Driver::create([
        'school_id' => $school->id,
        'user_id' => $driverUser->id,
        'name' => 'Driver Own',
        'phone' => '+254700000020',
        'license_no' => 'LIC-020',
        'license_expiry' => Carbon::now()->addYear()->toDateString(),
        'status' => 'active',
    ]);

    $route = TransportRoute::create([
        'school_id' => $school->id,
        'name' => 'Route 20',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'stops' => [],
        'monthly_fee' => 6000,
    ]);

    $trip = TransportTrip::create([
        'school_id' => $school->id,
        'transport_route_id' => $route->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'direction' => 'morning',
        'scheduled_start' => Carbon::now()->addHour(),
        'status' => TransportTrip::STATUS_SCHEDULED,
    ]);

    $response = $this->actingAs($driverUser)
        ->postJson("/api/v1/transport/trips/{$trip->id}/start");

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', TransportTrip::STATUS_IN_PROGRESS);

    expect($trip->fresh()->status)
        ->toBe(TransportTrip::STATUS_IN_PROGRESS);
});

test('assigned driver can end their own active trip', function () {
    $school = School::factory()->create();

    $driverUser = User::factory()->create([
        'school_id' => $school->id,
        'role' => 'driver',
        'status' => 'active',
    ]);

    $vehicle = Vehicle::create([
        'school_id' => $school->id,
        'registration_number' => 'BUS-211',
        'make' => 'Coach',
        'model' => 'Gamma',
        'capacity' => 40,
        'status' => 'active',
    ]);

    $driver = Driver::create([
        'school_id' => $school->id,
        'user_id' => $driverUser->id,
        'name' => 'Driver End',
        'phone' => '+254700000021',
        'license_no' => 'LIC-021',
        'license_expiry' => Carbon::now()->addYear()->toDateString(),
        'status' => 'active',
    ]);

    $route = TransportRoute::create([
        'school_id' => $school->id,
        'name' => 'Route 21',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'stops' => [],
        'monthly_fee' => 6000,
    ]);

    $trip = TransportTrip::create([
        'school_id' => $school->id,
        'transport_route_id' => $route->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'direction' => 'morning',
        'scheduled_start' => Carbon::now()->subHour(),
        'actual_start' => Carbon::now()->subHour(),
        'start_time' => Carbon::now()->subHour(),
        'status' => TransportTrip::STATUS_IN_PROGRESS,
    ]);

    $response = $this->actingAs($driverUser)
        ->postJson("/api/v1/transport/trips/{$trip->id}/end");

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', TransportTrip::STATUS_COMPLETED);

    expect($trip->fresh()->status)
        ->toBe(TransportTrip::STATUS_COMPLETED);
});

test('driver cannot start another drivers trip', function () {
    $school = School::factory()->create();

    $driverUser = User::factory()->create([
        'school_id' => $school->id,
        'role' => 'driver',
        'status' => 'active',
    ]);

    $assignedDriverUser = User::factory()->create([
        'school_id' => $school->id,
        'role' => 'driver',
        'status' => 'active',
    ]);

    $vehicle = Vehicle::create([
        'school_id' => $school->id,
        'registration_number' => 'BUS-212',
        'make' => 'Coach',
        'model' => 'Delta',
        'capacity' => 40,
        'status' => 'active',
    ]);

    $otherDriver = Driver::create([
        'school_id' => $school->id,
        'user_id' => $assignedDriverUser->id,
        'name' => 'Assigned Driver',
        'phone' => '+254700000022',
        'license_no' => 'LIC-022',
        'license_expiry' => Carbon::now()->addYear()->toDateString(),
        'status' => 'active',
    ]);

    Driver::create([
        'school_id' => $school->id,
        'user_id' => $driverUser->id,
        'name' => 'Unauthorized Driver',
        'phone' => '+254700000023',
        'license_no' => 'LIC-023',
        'license_expiry' => Carbon::now()->addYear()->toDateString(),
        'status' => 'active',
    ]);

    $route = TransportRoute::create([
        'school_id' => $school->id,
        'name' => 'Route 22',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $otherDriver->id,
        'stops' => [],
        'monthly_fee' => 6000,
    ]);

    $trip = TransportTrip::create([
        'school_id' => $school->id,
        'transport_route_id' => $route->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $otherDriver->id,
        'direction' => 'morning',
        'scheduled_start' => Carbon::now()->addHour(),
        'status' => TransportTrip::STATUS_SCHEDULED,
    ]);

    $response = $this->actingAs($driverUser)
        ->postJson("/api/v1/transport/trips/{$trip->id}/start");

    $response->assertStatus(403);

    expect($trip->fresh()->status)
        ->toBe(TransportTrip::STATUS_SCHEDULED);
});

test('driver cannot end another drivers trip', function () {
    $school = School::factory()->create();

    $driverUser = User::factory()->create([
        'school_id' => $school->id,
        'role' => 'driver',
        'status' => 'active',
    ]);

    $assignedDriverUser = User::factory()->create([
        'school_id' => $school->id,
        'role' => 'driver',
        'status' => 'active',
    ]);

    $vehicle = Vehicle::create([
        'school_id' => $school->id,
        'registration_number' => 'BUS-213',
        'make' => 'Coach',
        'model' => 'Delta',
        'capacity' => 40,
        'status' => 'active',
    ]);

    $otherDriver = Driver::create([
        'school_id' => $school->id,
        'user_id' => $assignedDriverUser->id,
        'name' => 'Assigned Driver',
        'phone' => '+254700000024',
        'license_no' => 'LIC-024',
        'license_expiry' => Carbon::now()->addYear()->toDateString(),
        'status' => 'active',
    ]);

    Driver::create([
        'school_id' => $school->id,
        'user_id' => $driverUser->id,
        'name' => 'Unauthorized Driver',
        'phone' => '+254700000025',
        'license_no' => 'LIC-025',
        'license_expiry' => Carbon::now()->addYear()->toDateString(),
        'status' => 'active',
    ]);

    $route = TransportRoute::create([
        'school_id' => $school->id,
        'name' => 'Route 23',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $otherDriver->id,
        'stops' => [],
        'monthly_fee' => 6000,
    ]);

    $trip = TransportTrip::create([
        'school_id' => $school->id,
        'transport_route_id' => $route->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $otherDriver->id,
        'direction' => 'morning',
        'scheduled_start' => Carbon::now()->subHour(),
        'actual_start' => Carbon::now()->subHour(),
        'start_time' => Carbon::now()->subHour(),
        'status' => TransportTrip::STATUS_IN_PROGRESS,
    ]);

    $response = $this->actingAs($driverUser)
        ->postJson("/api/v1/transport/trips/{$trip->id}/end");

    $response->assertStatus(403);

    expect($trip->fresh()->status)
        ->toBe(TransportTrip::STATUS_IN_PROGRESS);
});

test('driver cannot control a trip from another school', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    $driverUser = User::factory()->create([
        'school_id' => $schoolA->id,
        'role' => 'driver',
        'status' => 'active',
    ]);

    $driver = Driver::create([
        'school_id' => $schoolB->id,
        'name' => 'Other School Driver',
        'phone' => '+254700000026',
        'license_no' => 'LIC-026',
        'license_expiry' => Carbon::now()->addYear()->toDateString(),
        'status' => 'active',
    ]);

    $vehicle = Vehicle::create([
        'school_id' => $schoolB->id,
        'registration_number' => 'BUS-214',
        'make' => 'Coach',
        'model' => 'Epsilon',
        'capacity' => 40,
        'status' => 'active',
    ]);

    $route = TransportRoute::create([
        'school_id' => $schoolB->id,
        'name' => 'Other School Route',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'stops' => [],
        'monthly_fee' => 6000,
    ]);

    $trip = TransportTrip::create([
        'school_id' => $schoolB->id,
        'transport_route_id' => $route->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'direction' => 'morning',
        'scheduled_start' => Carbon::now()->addHour(),
        'status' => TransportTrip::STATUS_SCHEDULED,
    ]);

    $response = $this->actingAs($driverUser)
        ->postJson("/api/v1/transport/trips/{$trip->id}/start");

    $response->assertStatus(404);

    expect($trip->fresh()->status)
        ->toBe(TransportTrip::STATUS_SCHEDULED);
});

test('non-driver cannot start a trip', function () {
    $school = School::factory()->create();

    $user = User::factory()->create([
        'school_id' => $school->id,
        'role' => 'parent',
        'status' => 'active',
    ]);

    $vehicle = Vehicle::create([
        'school_id' => $school->id,
        'registration_number' => 'BUS-215',
        'make' => 'Coach',
        'model' => 'Zeta',
        'capacity' => 40,
        'status' => 'active',
    ]);

    $driver = Driver::create([
        'school_id' => $school->id,
        'name' => 'Actual Driver',
        'phone' => '+254700000027',
        'license_no' => 'LIC-027',
        'license_expiry' => Carbon::now()->addYear()->toDateString(),
        'status' => 'active',
    ]);

    $route = TransportRoute::create([
        'school_id' => $school->id,
        'name' => 'Route 25',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'stops' => [],
        'monthly_fee' => 6000,
    ]);

    $trip = TransportTrip::create([
        'school_id' => $school->id,
        'transport_route_id' => $route->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'direction' => 'morning',
        'scheduled_start' => Carbon::now()->addHour(),
        'status' => TransportTrip::STATUS_SCHEDULED,
    ]);

    $response = $this->actingAs($user)
        ->postJson("/api/v1/transport/trips/{$trip->id}/start");

    $response->assertStatus(403);

    expect($trip->fresh()->status)
        ->toBe(TransportTrip::STATUS_SCHEDULED);
});

test('non-driver cannot end a trip', function () {
    $school = School::factory()->create();

    $user = User::factory()->create([
        'school_id' => $school->id,
        'role' => 'parent',
        'status' => 'active',
    ]);

    $vehicle = Vehicle::create([
        'school_id' => $school->id,
        'registration_number' => 'BUS-216',
        'make' => 'Coach',
        'model' => 'Zeta',
        'capacity' => 40,
        'status' => 'active',
    ]);

    $driver = Driver::create([
        'school_id' => $school->id,
        'name' => 'Actual Driver',
        'phone' => '+254700000028',
        'license_no' => 'LIC-028',
        'license_expiry' => Carbon::now()->addYear()->toDateString(),
        'status' => 'active',
    ]);

    $route = TransportRoute::create([
        'school_id' => $school->id,
        'name' => 'Route 26',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'stops' => [],
        'monthly_fee' => 6000,
    ]);

    $trip = TransportTrip::create([
        'school_id' => $school->id,
        'transport_route_id' => $route->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'direction' => 'morning',
        'scheduled_start' => Carbon::now()->subHour(),
        'actual_start' => Carbon::now()->subHour(),
        'start_time' => Carbon::now()->subHour(),
        'status' => TransportTrip::STATUS_IN_PROGRESS,
    ]);

    $response = $this->actingAs($user)
        ->postJson("/api/v1/transport/trips/{$trip->id}/end");

    $response->assertStatus(403);

    expect($trip->fresh()->status)
        ->toBe(TransportTrip::STATUS_IN_PROGRESS);
});

test('starting a trip preserves its vehicle driver and route assignment', function () {
    $school = School::factory()->create();

    $user = User::factory()->create([
        'school_id' => $school->id,
        'role' => 'admin',
        'status' => 'active',
    ]);

    $vehicle = Vehicle::create([
        'school_id' => $school->id,
        'registration_number' => 'BUS-217',
        'make' => 'Coach',
        'model' => 'Eta',
        'capacity' => 40,
        'status' => 'active',
    ]);

    $driver = Driver::create([
        'school_id' => $school->id,
        'name' => 'Driver Assignment',
        'phone' => '+254700000029',
        'license_no' => 'LIC-029',
        'license_expiry' => Carbon::now()->addYear()->toDateString(),
        'status' => 'active',
    ]);

    $route = TransportRoute::create([
        'school_id' => $school->id,
        'name' => 'Route 27',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'stops' => [],
        'monthly_fee' => 6000,
    ]);

    $trip = TransportTrip::create([
        'school_id' => $school->id,
        'transport_route_id' => $route->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'direction' => 'morning',
        'scheduled_start' => Carbon::now()->addHour(),
        'status' => TransportTrip::STATUS_SCHEDULED,
    ]);

    $this->actingAs($user)
        ->postJson("/api/v1/transport/trips/{$trip->id}/start")
        ->assertStatus(200);

    $freshTrip = $trip->fresh();

    expect($freshTrip->transport_route_id)->toBe($route->id)
        ->and($freshTrip->vehicle_id)->toBe($vehicle->id)
        ->and($freshTrip->driver_id)->toBe($driver->id);
});

test('completing a trip preserves its recorded locations', function () {
    $school = School::factory()->create();

    $user = User::factory()->create([
        'school_id' => $school->id,
        'role' => 'admin',
        'status' => 'active',
    ]);

    $vehicle = Vehicle::create([
        'school_id' => $school->id,
        'registration_number' => 'BUS-218',
        'make' => 'Coach',
        'model' => 'Theta',
        'capacity' => 40,
        'status' => 'active',
    ]);

    $driver = Driver::create([
        'school_id' => $school->id,
        'name' => 'Driver History',
        'phone' => '+254700000030',
        'license_no' => 'LIC-030',
        'license_expiry' => Carbon::now()->addYear()->toDateString(),
        'status' => 'active',
    ]);

    $route = TransportRoute::create([
        'school_id' => $school->id,
        'name' => 'Route 28',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'stops' => [],
        'monthly_fee' => 6000,
    ]);

    $trip = TransportTrip::create([
        'school_id' => $school->id,
        'transport_route_id' => $route->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'direction' => 'morning',
        'scheduled_start' => Carbon::now()->subHour(),
        'actual_start' => Carbon::now()->subHour(),
        'start_time' => Carbon::now()->subHour(),
        'status' => TransportTrip::STATUS_IN_PROGRESS,
    ]);

    \App\Models\Transport\TripLocation::create([
        'transport_trip_id' => $trip->id,
        'lat' => -1.2825,
        'lng' => 36.8146,
        'speed' => 35,
        'heading' => 180,
        'accuracy' => 8.5,
        'recorded_at' => Carbon::now()->subMinutes(10),
    ]);

    $this->actingAs($user)
        ->postJson("/api/v1/transport/trips/{$trip->id}/end")
        ->assertStatus(200)
        ->assertJsonPath('data.status', TransportTrip::STATUS_COMPLETED);

    expect(\App\Models\Transport\TripLocation::where('transport_trip_id', $trip->id)->count())
        ->toBe(1);

    $location = \App\Models\Transport\TripLocation::where('transport_trip_id', $trip->id)->first();

    expect((float) $location->lat)->toBe(-1.2825)
        ->and((float) $location->lng)->toBe(36.8146)
        ->and((int) $location->speed)->toBe(35);
});