<?php

use App\Models\Driver;
use App\Models\School;
use App\Models\Transport\VehicleDriverAssignment;
use App\Models\Vehicle;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('admin can create and end a vehicle driver assignment', function () {
    $school = School::factory()->create();

    $user = User::factory()->create([
        'school_id' => $school->id,
        'role' => 'admin',
        'status' => 'active',
    ]);

    $vehicle = Vehicle::create([
        'school_id' => $school->id,
        'registration_number' => 'BUS-102',
        'make' => 'Coach',
        'model' => 'Z',
        'capacity' => 40,
        'status' => 'active',
    ]);

    $driver = Driver::create([
        'school_id' => $school->id,
        'name' => 'Jane Driver',
        'phone' => '+254700000002',
        'license_no' => 'LIC-0002',
        'license_expiry' => Carbon::now()->addYear()->toDateString(),
        'status' => 'active',
    ]);

    $assignmentResponse = $this->actingAs($user)
        ->postJson("/api/v1/transport/vehicles/{$vehicle->id}/assignments", [
            'driver_id' => $driver->id,
            'started_at' => Carbon::now()->toIso8601String(),
            'notes' => 'Primary route assignment',
        ]);

    $assignmentResponse->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.vehicle_id', $vehicle->id)
        ->assertJsonPath('data.driver_id', $driver->id)
        ->assertJsonPath('data.status', 'active');

    $assignmentId = $assignmentResponse->json('data.id');

    $deleteResponse = $this->actingAs($user)
        ->deleteJson("/api/v1/transport/vehicles/{$vehicle->id}/assignments/{$assignmentId}");

    $deleteResponse->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'ended');

    $this->assertDatabaseHas('vehicle_driver_assignments', [
        'id' => $assignmentId,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'status' => 'ended',
    ]);
});

test('vehicle cannot have two active driver assignments', function () {
    $school = School::factory()->create();

    $user = User::factory()->create([
        'school_id' => $school->id,
        'role' => 'admin',
        'status' => 'active',
    ]);

    $vehicle = Vehicle::create([
        'school_id' => $school->id,
        'registration_number' => 'BUS-103',
        'make' => 'Coach',
        'model' => 'X',
        'capacity' => 35,
        'status' => 'active',
    ]);

    $driverA = Driver::create([
        'school_id' => $school->id,
        'name' => 'Driver A',
        'phone' => '+254700000003',
        'license_no' => 'LIC-0003',
        'license_expiry' => Carbon::now()->addYear()->toDateString(),
        'status' => 'active',
    ]);

    $driverB = Driver::create([
        'school_id' => $school->id,
        'name' => 'Driver B',
        'phone' => '+254700000004',
        'license_no' => 'LIC-0004',
        'license_expiry' => Carbon::now()->addYear()->toDateString(),
        'status' => 'active',
    ]);

    $this->actingAs($user)
        ->postJson("/api/v1/transport/vehicles/{$vehicle->id}/assignments", [
            'driver_id' => $driverA->id,
            'started_at' => Carbon::now()->toIso8601String(),
        ])
        ->assertStatus(201);

    $this->actingAs($user)
        ->postJson("/api/v1/transport/vehicles/{$vehicle->id}/assignments", [
            'driver_id' => $driverB->id,
            'started_at' => Carbon::now()->toIso8601String(),
        ])
        ->assertStatus(422);
});

test('driver cannot have two active vehicle assignments', function () {
    $school = School::factory()->create();

    $user = User::factory()->create([
        'school_id' => $school->id,
        'role' => 'admin',
        'status' => 'active',
    ]);

    $vehicleA = Vehicle::create([
        'school_id' => $school->id,
        'registration_number' => 'BUS-104',
        'make' => 'Coach',
        'model' => 'Y',
        'capacity' => 38,
        'status' => 'active',
    ]);

    $vehicleB = Vehicle::create([
        'school_id' => $school->id,
        'registration_number' => 'BUS-105',
        'make' => 'Coach',
        'model' => 'Z',
        'capacity' => 38,
        'status' => 'active',
    ]);

    $driver = Driver::create([
        'school_id' => $school->id,
        'name' => 'Driver C',
        'phone' => '+254700000005',
        'license_no' => 'LIC-0005',
        'license_expiry' => Carbon::now()->addYear()->toDateString(),
        'status' => 'active',
    ]);

    $this->actingAs($user)
        ->postJson("/api/v1/transport/vehicles/{$vehicleA->id}/assignments", [
            'driver_id' => $driver->id,
            'started_at' => Carbon::now()->toIso8601String(),
        ])
        ->assertStatus(201);

    $this->actingAs($user)
        ->postJson("/api/v1/transport/vehicles/{$vehicleB->id}/assignments", [
            'driver_id' => $driver->id,
            'started_at' => Carbon::now()->toIso8601String(),
        ])
        ->assertStatus(422);
});
