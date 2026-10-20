<?php

use App\Models\School;
use App\Models\User;
use App\Notifications\EventCreatedNotification;
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

function eventNotificationPayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'School Open Day',
        'description' => 'Meet our school community.',
        'event_date' => now()->addWeek()->toDateString(),
        'type' => 'event',
        'venue' => 'Main Hall',
        'notify_all' => false,
    ], $overrides);
}

test('creating an event sends an in-app notification to the creator and school when selected', function () {
    $parent = User::factory()->create([
        'school_id' => $this->school->id,
        'role' => 'parent',
        'status' => 'active',
    ]);
    $otherSchoolUser = User::factory()->create([
        'school_id' => School::factory()->create()->id,
        'role' => 'teacher',
        'status' => 'active',
    ]);

    $response = $this->actingAs($this->admin)
        ->postJson('/api/v1/events', eventNotificationPayload(['notify_all' => true]));

    $response->assertCreated();

    expect($this->admin->fresh()->unreadNotifications()->where('type', EventCreatedNotification::class)->count())->toBe(1);
    expect($parent->fresh()->unreadNotifications()->where('type', EventCreatedNotification::class)->count())->toBe(1);
    expect($otherSchoolUser->fresh()->unreadNotifications()->count())->toBe(0);

    $this->actingAs($parent)
        ->getJson('/api/v1/notifications/event-unread-count')
        ->assertOk()
        ->assertJsonPath('data.event_count', 1);

    $this->actingAs($parent)
        ->postJson('/api/v1/notifications/events/read')
        ->assertOk()
        ->assertJsonPath('data.marked_read', 1);

    $this->actingAs($parent)
        ->getJson('/api/v1/notifications/event-unread-count')
        ->assertOk()
        ->assertJsonPath('data.event_count', 0);
});

test('creating an event without notify all only notifies the creator', function () {
    $staff = User::factory()->create([
        'school_id' => $this->school->id,
        'role' => 'teacher',
        'status' => 'active',
    ]);

    $this->actingAs($this->admin)
        ->postJson('/api/v1/events', eventNotificationPayload())
        ->assertCreated();

    expect($this->admin->fresh()->unreadNotifications()->where('type', EventCreatedNotification::class)->count())->toBe(1);
    expect($staff->fresh()->unreadNotifications()->count())->toBe(0);
});
