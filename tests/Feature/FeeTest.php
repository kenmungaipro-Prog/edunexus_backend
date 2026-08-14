<?php

// ============================================================
// tests/Feature/FeeTest.php
// ============================================================
use App\Models\{
    AcademicSession,
    ClassRoom,
    Fee,
    FeeType,
    School,
    Student,
    User
};

use Illuminate\Foundation\Testing\RefreshDatabase;


uses(RefreshDatabase::class);

beforeEach(function () {
    $this->school     = School::factory()->create();
    $this->session    = AcademicSession::factory()->create(['school_id' => $this->school->id, 'is_current' => true]);
    $this->class      = ClassRoom::factory()->create(['school_id' => $this->school->id, 'session_id' => $this->session->id]);
    $this->student    = Student::factory()->create(['school_id' => $this->school->id, 'class_id' => $this->class->id, 'session_id' => $this->session->id]);
    $this->feeType    = FeeType::factory()->create(['school_id' => $this->school->id, 'amount' => 12500]);
    $this->accountant = User::factory()->create(['school_id' => $this->school->id, 'role' => 'accountant', 'status' => 'active']);
    $this->admin      = User::factory()->create(['school_id' => $this->school->id, 'role' => 'admin', 'status' => 'active']);
});

test('accountant can collect a fee', function () {
    $response = $this->actingAs($this->accountant)
        ->postJson('/api/v1/fees/collect', [
            'student_id'     => $this->student->id,
            'fee_type_id'    => $this->feeType->id,
            'amount'         => 12500,
            'payment_method' => 'cash',
        ]);

    $response->assertStatus(201)
             ->assertJsonPath('success', true)
             ->assertJsonStructure(['data' => ['receipt_no', 'amount', 'status']]);

    $this->assertDatabaseHas('fees', [
        'student_id'     => $this->student->id,
        'amount'         => 12500,
        'status'         => 'paid',
        'payment_method' => 'cash',
    ]);
});

test('fee requires transaction id for upi payment', function () {
    $this->actingAs($this->accountant)
        ->postJson('/api/v1/fees/collect', [
            'student_id'     => $this->student->id,
            'fee_type_id'    => $this->feeType->id,
            'amount'         => 12500,
            'payment_method' => 'upi',
            // missing transaction_id
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['transaction_id']);
});

test('fee summary returns correct totals', function () {
    Fee::factory(3)->create([
        'student_id'   => $this->student->id,
        'fee_type_id'  => $this->feeType->id,
        'session_id'   => $this->session->id,
        'collected_by' => $this->accountant->id,
        'amount'       => 5000,
        'status'       => 'paid',
    ]);

    $response = $this->actingAs($this->admin)
        ->getJson('/api/v1/fees/summary');

    $response->assertStatus(200)
             ->assertJsonPath('data.total_collected', 15000);
});

test('teacher cannot access fee endpoints', function () {
    $teacher = User::factory()->create(['school_id' => $this->school->id, 'role' => 'teacher', 'status' => 'active']);

    $this->actingAs($teacher)
        ->postJson('/api/v1/fees/collect', [])
        ->assertStatus(403);
});

test('receipt generates pdf for paid fee', function () {
    $fee = Fee::factory()->create([
        'student_id'   => $this->student->id,
        'fee_type_id'  => $this->feeType->id,
        'session_id'   => $this->session->id,
        'collected_by' => $this->accountant->id,
        'status'       => 'paid',
    ]);

    $this->actingAs($this->admin)
        ->get("/api/v1/fees/{$fee->id}/receipt")
        ->assertStatus(200)
        ->assertHeader('Content-Type', 'application/pdf');
});
