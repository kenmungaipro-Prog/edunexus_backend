<?php

use App\Models\School;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('authenticated school users can load print branding', function () {
    $school = School::factory()->create([
        'name' => 'Northside School',
        'logo' => 'schools/logos/northside.png',
    ]);
    $teacher = User::factory()->create([
        'school_id' => $school->id,
        'role' => 'teacher',
        'status' => 'active',
    ]);

    $this->actingAs($teacher)
        ->getJson('/api/v1/settings/school/branding')
        ->assertOk()
        ->assertJsonPath('data.name', 'Northside School')
        ->assertJsonPath('data.logo', 'schools/logos/northside.png');
});

test('admins can upload a school logo from settings', function () {
    Storage::fake('public');
    $school = School::factory()->create();
    $admin = User::factory()->create([
        'school_id' => $school->id,
        'role' => 'admin',
        'status' => 'active',
    ]);

    $response = $this->actingAs($admin)
        ->post('/api/v1/settings/school', [
            '_method' => 'PUT',
            'logo' => UploadedFile::fake()->image('school-logo.png'),
        ]);

    $response->assertOk();
    $logoPath = $response->json('data.logo');

    expect($logoPath)->toStartWith('schools/logos/');
    Storage::disk('public')->assertExists($logoPath);
    $this->assertDatabaseHas('schools', [
        'id' => $school->id,
        'logo' => $logoPath,
    ]);
});
