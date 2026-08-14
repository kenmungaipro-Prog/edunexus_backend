<?php

use App\Models\School;
use App\Models\Transport\TransportRoute;
use App\Models\Transport\TransportStop;
use App\Models\Transport\TransportTrip;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Driver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeTransportStopScenario(): array
{
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    $admin = User::factory()->create([
        'school_id' => $schoolA->id,
        'role' => 'admin',
        'status' => 'active',
    ]);

    $driver = User::factory()->create([
        'school_id' => $schoolA->id,
        'role' => 'driver',
        'status' => 'active',
    ]);

    $driverRecord = Driver::create([
        'school_id' => $schoolA->id,
        'user_id' => $driver->id,
        'name' => 'Test Driver',
        'phone' => '+254700000001',
        'license_no' => 'LIC-1001',
        'license_expiry' => now()->addYear()->toDateString(),
        'status' => 'active',
    ]);

    $vehicle = Vehicle::create([
        'school_id' => $schoolA->id,
        'registration_number' => 'BUS-100',
        'make' => 'Toyota',
        'model' => 'Coaster',
        'capacity' => 30,
        'status' => 'active',
    ]);

    $route = TransportRoute::create([
        'school_id' => $schoolA->id,
        'name' => 'Route One',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driverRecord->id,
        'stops' => [],
        'monthly_fee' => 4500,
    ]);

    $stopOne = TransportStop::create([
        'transport_route_id' => $route->id,
        'name' => 'School Gate',
        'latitude' => -1.2921,
        'longitude' => 36.8219,
        'sequence' => 1,
        'pickup_time' => '06:30',
        'dropoff_time' => '07:00',
        'geofence_radius' => 120,
        'status' => 'active',
    ]);

    $stopTwo = TransportStop::create([
        'transport_route_id' => $route->id,
        'name' => 'Town Centre',
        'latitude' => -1.2833,
        'longitude' => 36.8167,
        'sequence' => 2,
        'pickup_time' => '07:05',
        'dropoff_time' => '07:25',
        'geofence_radius' => 120,
        'status' => 'active',
    ]);

    $trip = TransportTrip::create([
        'school_id' => $schoolA->id,
        'transport_route_id' => $route->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driverRecord->id,
        'direction' => 'morning',
        'scheduled_start' => now()->addDay(),
        'status' => 'scheduled',
    ]);

    return compact('schoolA', 'schoolB', 'admin', 'driver', 'route', 'stopOne', 'stopTwo', 'trip');
}

test('admin can create a stop', function () {
    $data = makeTransportStopScenario();

    $response = $this->actingAs($data['admin'])
        ->postJson('/api/v1/transport/routes/' . $data['route']->id . '/stops', [
            'name' => 'Muguga Road',
            'latitude' => -1.3000,
            'longitude' => 36.8200,
            'sequence' => 3,
            'pickup_time' => '08:00',
            'dropoff_time' => '08:20',
            'geofence_radius' => 150,
            'status' => 'active',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'Muguga Road')
        ->assertJsonPath('data.transport_route_id', $data['route']->id);

    $this->assertDatabaseHas('transport_stops', [
        'transport_route_id' => $data['route']->id,
        'name' => 'Muguga Road',
        'sequence' => 3,
    ]);
});

test('admin can read, update and delete a stop', function () {
    $data = makeTransportStopScenario();

    $read = $this->actingAs($data['admin'])
        ->getJson('/api/v1/transport/routes/' . $data['route']->id . '/stops');
    $read->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonCount(2, 'data');

    $update = $this->actingAs($data['admin'])
        ->putJson('/api/v1/transport/routes/' . $data['route']->id . '/stops/' . $data['stopOne']->id, [
            'name' => 'Main Gate Updated',
            'sequence' => 10,
        ]);

    $update->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'Main Gate Updated');

    $this->assertDatabaseHas('transport_stops', [
        'id' => $data['stopOne']->id,
        'name' => 'Main Gate Updated',
        'sequence' => 10,
    ]);

    $delete = $this->actingAs($data['admin'])
        ->deleteJson('/api/v1/transport/routes/' . $data['route']->id . '/stops/' . $data['stopOne']->id);

    $delete->assertStatus(200)
        ->assertJsonPath('success', true);

    $this->assertDatabaseMissing('transport_stops', [
        'id' => $data['stopOne']->id,
    ]);
});

test('stop belongs to the school that owns the route', function () {
    $data = makeTransportStopScenario();

    $this->assertEquals($data['schoolA']->id, $data['route']->school_id);
    $this->assertEquals($data['schoolA']->id, $data['stopOne']->route->school_id);
    $this->assertNotEquals($data['schoolB']->id, $data['route']->school_id);
});

test('route returns stops in order', function () {
    $data = makeTransportStopScenario();

    $response = $this->actingAs($data['admin'])
        ->getJson('/api/v1/transport/routes/' . $data['route']->id . '/stops');

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.0.name', 'School Gate')
        ->assertJsonPath('data.1.name', 'Town Centre');

    $stops = collect($response->json('data'));
    $this->assertSame([1, 2], $stops->pluck('sequence')->all());
});

test('driver cannot create a stop', function () {
    $data = makeTransportStopScenario();

    $response = $this->actingAs($data['driver'])
        ->postJson('/api/v1/transport/routes/' . $data['route']->id . '/stops', [
            'name' => 'Driver Stop',
            'latitude' => -1.3100,
            'longitude' => 36.8101,
            'sequence' => 3,
        ]);

    $response->assertStatus(403)
        ->assertJsonPath('success', false);

    $this->assertDatabaseMissing('transport_stops', [
        'name' => 'Driver Stop',
    ]);
});

test('other school cannot access stops', function () {
    $data = makeTransportStopScenario();

    $otherAdmin = User::factory()->create([
        'school_id' => $data['schoolB']->id,
        'role' => 'admin',
        'status' => 'active',
    ]);

    $response = $this->actingAs($otherAdmin)
        ->getJson('/api/v1/transport/routes/' . $data['route']->id . '/stops');

    $response->assertStatus(404)
        ->assertJsonPath('success', false);
});

test('trip stop links to transport stop', function () {
    $data = makeTransportStopScenario();

    $response = $this->actingAs($data['admin'])
        ->postJson('/api/v1/transport/trips/' . $data['trip']->id . '/stops/' . $data['stopOne']->id, [
            'sequence' => 1,
            'status' => 'pending',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.transport_stop_id', $data['stopOne']->id)
        ->assertJsonPath('data.name', 'School Gate');

    $this->assertDatabaseHas('trip_stops', [
        'transport_trip_id' => $data['trip']->id,
        'transport_stop_id' => $data['stopOne']->id,
        'name' => 'School Gate',
    ]);
});

test('duplicate stop order validation prevents duplicate sequence values', function () {
    $data = makeTransportStopScenario();

    $response = $this->actingAs($data['admin'])
        ->postJson('/api/v1/transport/routes/' . $data['route']->id . '/stops', [
            'name' => 'Duplicate Sequence Stop',
            'latitude' => -1.3200,
            'longitude' => 36.8300,
            'sequence' => 1,
            'pickup_time' => '09:00',
            'dropoff_time' => '09:20',
        ]);

    $response->assertStatus(500)
        ->assertJsonPath('message', 'A stop with this sequence already exists for the route.');
});
