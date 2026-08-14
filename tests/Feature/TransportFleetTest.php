<?php

use App\Models\Driver;
use App\Models\School;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('admin can manage vehicles within their school', function () {
    $school = School::factory()->create();

    $user = User::factory()->create([
        'school_id' => $school->id,
        'role' => 'admin',
        'status' => 'active',
    ]);

    $createResponse = $this->actingAs($user)
        ->postJson('/api/v1/transport/vehicles', [
            'registration_number' => 'BUS-101',
            'make' => 'TestCoach',
            'model' => 'Alpha',
            'capacity' => 30,
            'status' => 'active',
        ]);

    $createResponse->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.registration_number', 'BUS-101')
        ->assertJsonPath('data.school_id', $school->id);

    $vehicleId = $createResponse->json('data.id');

    $showResponse = $this->actingAs($user)
        ->getJson("/api/v1/transport/vehicles/{$vehicleId}");

    $showResponse->assertStatus(200)
        ->assertJsonPath('data.id', $vehicleId)
        ->assertJsonPath('data.registration_number', 'BUS-101');

    $updateResponse = $this->actingAs($user)
        ->putJson("/api/v1/transport/vehicles/{$vehicleId}", [
            'status' => 'inactive',
        ]);

    $updateResponse->assertStatus(200)
        ->assertJsonPath('data.status', 'inactive');

    $deleteResponse = $this->actingAs($user)
        ->deleteJson("/api/v1/transport/vehicles/{$vehicleId}");

    $deleteResponse->assertStatus(200)
        ->assertJsonPath('success', true);

    $this->actingAs($user)
        ->getJson("/api/v1/transport/vehicles/{$vehicleId}")
        ->assertStatus(404);
});

test('admin can manage drivers within their school', function () {
    $school = School::factory()->create();

    $user = User::factory()->create([
        'school_id' => $school->id,
        'role' => 'admin',
        'status' => 'active',
    ]);

    $createResponse = $this->actingAs($user)
        ->postJson('/api/v1/transport/drivers', [
            'name' => 'Driver One',
            'phone' => '+254700000001',
            'license_no' => 'LIC-0001',
            'license_expiry' => Carbon::now()->addYear()->toDateString(),
            'status' => 'active',
        ]);

    $createResponse->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'Driver One')
        ->assertJsonPath('data.school_id', $school->id);

    $driverId = $createResponse->json('data.id');

    $showResponse = $this->actingAs($user)
        ->getJson("/api/v1/transport/drivers/{$driverId}");

    $showResponse->assertStatus(200)
        ->assertJsonPath('data.id', $driverId)
        ->assertJsonPath('data.name', 'Driver One');

    $updateResponse = $this->actingAs($user)
        ->putJson("/api/v1/transport/drivers/{$driverId}", [
            'status' => 'inactive',
        ]);

    $updateResponse->assertStatus(200)
        ->assertJsonPath('data.status', 'inactive');

    $deleteResponse = $this->actingAs($user)
        ->deleteJson("/api/v1/transport/drivers/{$driverId}");

    $deleteResponse->assertStatus(200)
        ->assertJsonPath('success', true);

    $this->actingAs($user)
        ->getJson("/api/v1/transport/drivers/{$driverId}")
        ->assertStatus(404);
});
