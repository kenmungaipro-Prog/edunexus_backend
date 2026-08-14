<?php

// ============================================================
// tests/Feature/AuthTest.php
// Run: php artisan test --filter AuthTest
// ============================================================

use App\Models\User;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->school = School::factory()->create();
    $this->admin  = User::factory()->create([
        'school_id' => $this->school->id,
        'role'      => 'admin',
        'email'     => 'admin@test.com',
        'password'  => bcrypt('password'),
        'status'    => 'active',
    ]);
});

test('admin can login with correct credentials', function () {
    $response = $this->postJson('/api/v1/auth/login', [
        'email'    => 'admin@test.com',
        'password' => 'password',
    ]);

    $response->assertStatus(200)
             ->assertJsonStructure([
                 'success',
                 'data' => ['user' => ['id', 'name', 'email', 'role'], 'token'],
             ]);

    expect($response->json('success'))->toBeTrue();
    expect($response->json('data.user.role'))->toBe('admin');
});

test('login fails with wrong password', function () {
    $this->postJson('/api/v1/auth/login', [
        'email'    => 'admin@test.com',
        'password' => 'wrong-password',
    ])->assertStatus(422);
});

test('login fails for inactive user', function () {
    $this->admin->update(['status' => 'inactive']);

    $rows = DB::table('users')
        ->where('email', 'admin@test.com')
        ->get(['id', 'email', 'status', 'school_id']);
expect($this->admin->fresh()->status)->toBe('inactive');

    $this->postJson('/api/v1/auth/login', [
        'email'    => 'admin@test.com',
        'password' => 'password',
    ])->assertStatus(403);
});

test('authenticated user can get their profile', function () {
    $response = $this->actingAs($this->admin)
        ->getJson('/api/v1/auth/me');

    $response->assertStatus(200)
             ->assertJsonPath('data.id',    $this->admin->id)
             ->assertJsonPath('data.email', $this->admin->email);
});

test('unauthenticated request returns 401', function () {
    $this->getJson('/api/v1/auth/me')->assertStatus(401);
});

test('user can logout', function () {
    $this->actingAs($this->admin)
        ->postJson('/api/v1/auth/logout')
        ->assertStatus(200)
        ->assertJsonPath('success', true);
});

test('user can change password', function () {
    $this->actingAs($this->admin)
        ->postJson('/api/v1/auth/change-password', [
            'current_password'      => 'password',
            'password'              => 'NewPassword@123',
            'password_confirmation' => 'NewPassword@123',
        ])
        ->assertStatus(200);
});

test('teacher cannot access admin-only endpoints', function () {
    $teacher = User::factory()->create([
        'school_id' => $this->school->id,
        'role'      => 'teacher',
        'status'    => 'active',
    ]);

    $this->actingAs($teacher)
        ->deleteJson('/api/v1/teachers/1')
        ->assertStatus(403);
});




