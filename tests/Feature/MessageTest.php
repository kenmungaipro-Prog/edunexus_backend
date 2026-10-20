<?php

use App\Models\Message;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->school = School::factory()->create();
    $this->sender = User::factory()->create([
        'school_id' => $this->school->id,
        'role' => 'admin',
        'status' => 'active',
    ]);
});

test('contacts only includes active users from the signed-in users school', function () {
    $contact = User::factory()->create([
        'school_id' => $this->school->id,
        'role' => 'teacher',
        'status' => 'active',
    ]);
    User::factory()->create([
        'school_id' => $this->school->id,
        'role' => 'student',
        'status' => 'inactive',
    ]);
    User::factory()->create([
        'school_id' => School::factory()->create()->id,
        'role' => 'teacher',
        'status' => 'active',
    ]);

    $this->actingAs($this->sender)
        ->getJson('/api/v1/messages/contacts')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $contact->id);
});

test('school users can message each other and unread messages clear when read', function () {
    $recipient = User::factory()->create([
        'school_id' => $this->school->id,
        'role' => 'parent',
        'status' => 'active',
    ]);

    $this->actingAs($this->sender)
        ->postJson('/api/v1/messages', [
            'receiver_id' => $recipient->id,
            'body' => 'Please check the school notice.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.body', 'Please check the school notice.');

    $this->actingAs($recipient)
        ->getJson('/api/v1/messages/unread-count')
        ->assertOk()
        ->assertJsonPath('data.count', 1);

    $this->actingAs($recipient)
        ->getJson("/api/v1/messages/{$this->sender->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data');

    expect(Message::first()->fresh()->read_at)->not->toBeNull();

    $this->actingAs($recipient)
        ->getJson('/api/v1/messages/unread-count')
        ->assertOk()
        ->assertJsonPath('data.count', 0);
});

test('users cannot message or read conversations with a different school', function () {
    $otherSchoolUser = User::factory()->create([
        'school_id' => School::factory()->create()->id,
        'role' => 'admin',
        'status' => 'active',
    ]);

    $this->actingAs($this->sender)
        ->postJson('/api/v1/messages', [
            'receiver_id' => $otherSchoolUser->id,
            'body' => 'This should not be delivered.',
        ])
        ->assertUnprocessable();

    $this->actingAs($this->sender)
        ->getJson("/api/v1/messages/{$otherSchoolUser->id}")
        ->assertNotFound();
});
