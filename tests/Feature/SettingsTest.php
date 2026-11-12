<?php

use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->school = School::factory()->create();
    $this->admin = User::factory()->create([
        'school_id' => $this->school->id,
        'role' => 'admin',
        'status' => 'active',
    ]);
});

test('school settings return persisted preferences with defaults', function () {
    $this->actingAs($this->admin)
        ->getJson('/api/v1/settings')
        ->assertOk()
        ->assertJsonPath('data.preferences.currency', 'KES')
        ->assertJsonPath('data.preferences.timezone', 'Africa/Nairobi')
        ->assertJsonPath('data.notifications.email_alerts', true);
});

test('school preference updates persist and preserve other setting values', function () {
    $this->school->update([
        'settings' => [
            'notifications' => ['email_alerts' => false],
            'academic' => ['attendance_threshold' => 80],
        ],
    ]);

    $this->actingAs($this->admin)
        ->putJson('/api/v1/settings', [
            'notifications' => [
                'sms_alerts' => true,
                'fee_reminders' => false,
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.notifications.email_alerts', false)
        ->assertJsonPath('data.notifications.sms_alerts', true)
        ->assertJsonPath('data.notifications.fee_reminders', false)
        ->assertJsonPath('data.academic.attendance_threshold', 80);

    expect($this->school->fresh()->settings['notifications']['sms_alerts'])->toBeTrue();
});

test('school settings reject invalid notification preferences', function () {
    $this->actingAs($this->admin)
        ->putJson('/api/v1/settings', [
            'notifications' => ['sms_alerts' => 'sometimes'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['notifications.sms_alerts']);
});

test('school profile fields save and optional profile values can be cleared', function () {
    $this->actingAs($this->admin)
        ->putJson('/api/v1/settings/school', [
            'name' => 'Updated School',
            'email' => $this->school->email,
            'principal' => null,
            'phone' => null,
            'address' => null,
            'board' => null,
            'admission_prefix' => 'spsk',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Updated School')
        ->assertJsonPath('data.admission_prefix', 'SPSK');

    $updatedSchool = $this->school->fresh();
    expect($updatedSchool->principal)->toBeNull()
        ->and($updatedSchool->phone)->toBeNull()
        ->and($updatedSchool->address)->toBeNull()
        ->and($updatedSchool->board)->toBeNull();
});

test('school profile update ignores unvalidated settings payload', function () {
    $this->actingAs($this->admin)
        ->putJson('/api/v1/settings/school', [
            'name' => 'Updated School',
            'settings' => ['notifications' => ['sms_alerts' => true]],
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Updated School');

    expect($this->school->fresh()->settings)->toBeNull();
});
