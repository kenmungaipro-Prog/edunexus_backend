<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Student;
use App\Models\Invoice;
use App\Models\School;
use App\Models\AcademicSession;
use Illuminate\Support\Facades\DB;

class MpesaFailureAndAutoAllocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_stk_failure_marks_transaction_failed()
    {
        // Seed a pending gateway transaction to be marked failed
        $schoolId = DB::table('schools')->insertGetId([
            'name' => 'Test School',
            'email' => 'test@school.example',
            'phone' => '254700000000',
            'address' => '123 Test Ave',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('payment_gateway_transactions')->insert([
            'school_id' => $schoolId,
            'student_id' => null,
            'gateway_name' => 'mpesa',
            'transaction_type' => 'STK_PUSH',
            'merchant_request_id' => 'MID_FAIL',
            'checkout_request_id' => 'CID_FAIL',
            'amount' => 100.00,
            'phone_number' => '254712345678',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Simulate STK callback without CallbackMetadata (user cancelled)
        $payload = [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'MID_FAIL',
                    'CheckoutRequestID' => 'CID_FAIL',
                    'ResultCode' => 1032,
                    'ResultDesc' => 'The transaction was cancelled by the user'
                ]
            ]
        ];

        $response = $this->postJson('/api/v1/payments/mpesa/stk-callback', $payload);
        $response->assertStatus(200)->assertJson(['ResultCode' => 0]);

        $this->assertDatabaseHas('mpesa_callbacks', [
            'merchant_request_id' => 'MID_FAIL',
            'result_code' => '1032'
        ]);

        $this->assertDatabaseHas('payment_gateway_transactions', [
            'merchant_request_id' => 'MID_FAIL',
            'status' => 'failed'
        ]);
    }

    public function test_c2b_auto_allocation_to_student_when_billref_matches_admission_no()
    {
        // Direct DB inserts to satisfy FK constraints quickly
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        $schoolId = DB::table('schools')->insertGetId([
            'name' => 'Test School',
            'email' => 'test@school.example',
            'phone' => '254700000000',
            'address' => '123 Test Ave',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sessionId = DB::table('academic_sessions')->insertGetId([
            'school_id' => $schoolId,
            'name' => '2025',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'is_current' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $classId = DB::table('class_rooms')->insertGetId([
            'school_id' => $schoolId,
            'session_id' => $sessionId,
            'name' => 'Grade 1-A',
            'grade' => 1,
            'section' => 'A',
            'capacity' => 40,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $studentId = DB::table('students')->insertGetId([
            'school_id' => $schoolId,
            'session_id' => $sessionId,
            'class_id' => $classId,
            'admission_no' => 'REF123',
            'roll_number' => '1',
            'first_name' => 'Test',
            'last_name' => 'Student',
            'date_of_birth' => now()->subYears(10)->toDateString(),
            'gender' => 'male',
            'admission_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $invoiceId = DB::table('invoices')->insertGetId([
            'school_id' => $schoolId,
            'student_id' => $studentId,
            'session_id' => $sessionId,
            'invoice_number' => 'INV-1',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addMonth()->toDateString(),
            'currency' => 'KES',
            'subtotal' => 500.00,
            'total' => 500.00,
            'balance' => 500.00,
            'status' => 'issued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $payload = [
            'TransID' => 'TID_AUTO',
            'TransAmount' => 300.00,
            'MSISDN' => '254712345678',
            'BillRefNumber' => 'REF123'
        ];

        $response = $this->postJson('/api/v1/payments/mpesa/c2b-confirmation', $payload);
        $response->assertStatus(200)->assertJson(['ResultCode' => 0]);

        // Payment should have been created and at least partially allocated
        $this->assertDatabaseHas('payments', [
            'amount' => '300.00',
            'school_id' => $schoolId
        ]);

        $this->assertDatabaseHas('payment_allocations', [
            'invoice_id' => $invoiceId
        ]);

        // No suspense item should be created for a matched reference
        $this->assertDatabaseMissing('payment_reconciliation_items', [
            'mpesa_receipt_number' => 'TID_AUTO'
        ]);
    }
}
