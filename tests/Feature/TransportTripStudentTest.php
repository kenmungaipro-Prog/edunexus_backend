<?php

use App\Models\AcademicSession;
use App\Models\ClassRoom;
use App\Models\Driver;
use App\Models\School;
use App\Models\Student;
use App\Models\Transport\TransportAssignment;
use App\Models\Transport\TransportRoute;
use App\Models\Transport\TransportStop;
use App\Models\Transport\TransportTrip;
use App\Models\Transport\TripStudent;
use App\Models\Vehicle;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function debugTripStudentTestStep(string $message): void
{
    file_put_contents(__DIR__ . '/../../tmp_trip_student_debug_steps.txt', $message . PHP_EOL, FILE_APPEND);
}

function createAssignedTripStudentTestData(): array
{
    debugTripStudentTestStep('start createAssignedTripStudentTestData');
    $school = School::factory()->create();
    debugTripStudentTestStep('school created ' . $school->id);
    $user = User::factory()->create([
        'school_id' => $school->id,
        'role' => 'admin',
        'status' => 'active',
    ]);

    $vehicle = Vehicle::create([
        'school_id' => $school->id,
        'registration_number' => 'BUS-250',
        'make' => 'Coach',
        'model' => 'Delta',
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

    $session = AcademicSession::factory()->create([
        'school_id' => $school->id,
        'is_current' => true,
    ]);

    $class = ClassRoom::factory()->create([
        'school_id' => $school->id,
        'session_id' => $session->id,
    ]);

    $student = Student::factory()->create([
        'school_id' => $school->id,
        'session_id' => $session->id,
        'class_id' => $class->id,
        'parent_id' => User::factory()->create(['school_id' => $school->id])->id,
        'admission_no' => 'S250',
        'roll_number' => 'R250',
        'first_name' => 'Brian',
        'last_name' => 'Njoroge',
        'status' => 'active',
    ]);

    $route = TransportRoute::create([
        'school_id' => $school->id,
        'name' => 'Route 17',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'stops' => [],
        'monthly_fee' => 6000,
    ]);

    $stopOne = TransportStop::create([
        'transport_route_id' => $route->id,
        'name' => 'Ruiru Stop 3',
        'latitude' => -1.2321,
        'longitude' => 36.8100,
        'sequence' => 1,
        'pickup_time' => '06:45',
        'dropoff_time' => '07:20',
        'status' => 'active',
    ]);

    $stopTwo = TransportStop::create([
        'transport_route_id' => $route->id,
        'name' => 'School Gate',
        'latitude' => -1.2340,
        'longitude' => 36.8120,
        'sequence' => 2,
        'pickup_time' => '07:20',
        'dropoff_time' => '07:35',
        'status' => 'active',
    ]);

    TransportAssignment::create([
        'transport_route_id' => $route->id,
        'student_id' => $student->id,
        'stop' => $stopOne->id,
    ]);
    debugTripStudentTestStep('assignment created');

    $trip = TransportTrip::create([
        'school_id' => $school->id,
        'transport_route_id' => $route->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'direction' => 'morning',
        'scheduled_start' => Carbon::now()->addDay(),
        'status' => TransportTrip::STATUS_SCHEDULED,
    ]);
    debugTripStudentTestStep('trip created ' . $trip->id);

    return compact('school', 'user', 'student', 'route', 'stopOne', 'stopTwo', 'trip');
}

test('admin can add a student to a trip as pending', function () {
    debugTripStudentTestStep('test started');
    extract(createAssignedTripStudentTestData());
    debugTripStudentTestStep('test data ready');

    debugTripStudentTestStep('before postJson create trip student');
    $response = $this->actingAs($user)
        ->postJson("/api/v1/transport/trips/{$trip->id}/students", [
            'student_id' => $student->id,
        ]);
    debugTripStudentTestStep('after postJson create trip student');

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.student_id', $student->id)
        ->assertJsonPath('data.status', TripStudent::STATUS_PENDING)
        ->assertJsonPath('data.boarding_time', null)
        ->assertJsonPath('data.dropoff_time', null);
});

test('duplicate student cannot be added to the same trip', function () {
    extract(createAssignedTripStudentTestData());

    $this->actingAs($user)
        ->postJson("/api/v1/transport/trips/{$trip->id}/students", [
            'student_id' => $student->id,
        ])
        ->assertStatus(201);

    $response = $this->actingAs($user)
        ->postJson("/api/v1/transport/trips/{$trip->id}/students", [
            'student_id' => $student->id,
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('success', false);
});

test('student can board a trip student record', function () {
    extract(createAssignedTripStudentTestData());

    $tripStudentResponse = $this->actingAs($user)
        ->postJson("/api/v1/transport/trips/{$trip->id}/students", [
            'student_id' => $student->id,
        ]);

    $tripStudentResponse->assertStatus(201);
    $tripStudentId = $tripStudentResponse->json('data.id');
    $boardingTime = Carbon::now()->addDay()->setTime(6, 48)->toIso8601String();

    $response = $this->actingAs($user)
        ->postJson("/api/v1/transport/trips/{$trip->id}/students/{$tripStudentId}/board", [
            'boarding_stop_id' => $stopOne->id,
            'boarding_time' => $boardingTime,
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', TripStudent::STATUS_BOARDED)
        ->assertJsonPath('data.boarding_stop_id', $stopOne->id);

    expect(Carbon::parse($response->json('data.boarding_time')))
        ->toEqual(Carbon::parse($boardingTime));
    });

test('student can be dropped off after boarding', function () {
    extract(createAssignedTripStudentTestData());

    $tripStudentResponse = $this->actingAs($user)
        ->postJson("/api/v1/transport/trips/{$trip->id}/students", [
            'student_id' => $student->id,
        ]);

    $tripStudentResponse->assertStatus(201);
    $tripStudentId = $tripStudentResponse->json('data.id');
    $boardingTime = Carbon::now()->addDay()->setTime(6, 48)->toIso8601String();

    $this->actingAs($user)
        ->postJson("/api/v1/transport/trips/{$trip->id}/students/{$tripStudentId}/board", [
            'boarding_stop_id' => $stopOne->id,
            'boarding_time' => $boardingTime,
        ])
        ->assertStatus(200);

    $dropoffTime = Carbon::now()->addDay()->setTime(7, 21)->toIso8601String();

    $response = $this->actingAs($user)
        ->postJson("/api/v1/transport/trips/{$trip->id}/students/{$tripStudentId}/drop-off", [
            'dropoff_stop_id' => $stopTwo->id,
            'dropoff_time' => $dropoffTime,
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', TripStudent::STATUS_DROPPED_OFF)
        ->assertJsonPath('data.dropoff_stop_id', $stopTwo->id);
        
    expect(Carbon::parse($response->json('data.dropoff_time')))
        ->toEqual(Carbon::parse($dropoffTime));
});

test('drop-off before boarding is rejected', function () {
    extract(createAssignedTripStudentTestData());

    $tripStudentResponse = $this->actingAs($user)
        ->postJson("/api/v1/transport/trips/{$trip->id}/students", [
            'student_id' => $student->id,
        ]);

    $tripStudentResponse->assertStatus(201);
    $tripStudentId = $tripStudentResponse->json('data.id');
    $dropoffTime = Carbon::now()->addDay()->setTime(7, 10)->toIso8601String();

    $response = $this->actingAs($user)
        ->postJson("/api/v1/transport/trips/{$trip->id}/students/{$tripStudentId}/drop-off", [
            'dropoff_stop_id' => $stopTwo->id,
            'dropoff_time' => $dropoffTime,
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('success', false);
});

test('completed trip cannot accept new trip students', function () {
    extract(createAssignedTripStudentTestData());

    $trip->update(['status' => TransportTrip::STATUS_COMPLETED]);

    $response = $this->actingAs($user)
        ->postJson("/api/v1/transport/trips/{$trip->id}/students", [
            'student_id' => $student->id,
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('success', false);
});

test('student without a transport assignment cannot be added to the trip', function () {
    extract(createAssignedTripStudentTestData());

    $unassignedSession = AcademicSession::factory()->create([
        'school_id' => $school->id,
        'is_current' => true,
    ]);

    $unassignedClass = ClassRoom::factory()->create([
        'school_id' => $school->id,
        'session_id' => $unassignedSession->id,
    ]);

    $unassignedStudent = Student::factory()->create([
        'school_id' => $school->id,
        'session_id' => $unassignedSession->id,
        'class_id' => $unassignedClass->id,
        'parent_id' => User::factory()->create(['school_id' => $school->id])->id,
        'admission_no' => 'S260',
        'roll_number' => 'R260',
        'first_name' => 'Grace',
        'last_name' => 'Wanjiru',
        'status' => 'active',
    ]);

    $response = $this->actingAs($user)
        ->postJson("/api/v1/transport/trips/{$trip->id}/students", [
            'student_id' => $unassignedStudent->id,
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('success', false);
});
