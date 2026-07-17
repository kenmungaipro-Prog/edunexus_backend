<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\ClassRoom;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MpesaStatusControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_accountant_can_view_mpesa_status_transactions(): void
    {
        $school = School::create([
            'name' => 'Test School',
            'address' => '123 Test Lane',
            'phone' => '254700000000',
            'email' => 'school@test.example',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $session = AcademicSession::create([
            'school_id' => $school->id,
            'name' => '2025',
            'is_current' => true,
            'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $classRoom = ClassRoom::create([
            'school_id' => $school->id,
            'session_id' => $session->id,
            'name' => 'Grade 1-A',
            'grade' => 1,
            'section' => 'A',
            'capacity' => 40,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $student = Student::create([
            'school_id' => $school->id,
            'session_id' => $session->id,
            'class_id' => $classRoom->id,
            'admission_no' => 'ADM-001',
            'roll_number' => '1',
            'first_name' => 'Test',
            'last_name' => 'Student',
            'date_of_birth' => now()->subYears(10)->toDateString(),
            'gender' => 'male',
            'address' => '456 Student Rd',
            'status' => 'active',
            'admission_date' => now()->subYear()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $accountant = User::create([
            'school_id' => $school->id,
            'name' => 'Accountant User',
            'email' => 'accountant@test.example',
            'password' => bcrypt('password'),
            'role' => 'accountant',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('payment_gateway_transactions')->insert([
            'school_id' => $school->id,
            'student_id' => $student->id,
            'gateway_name' => 'mpesa',
            'transaction_type' => 'STK_PUSH',
            'merchant_request_id' => 'MERCHANT-001',
            'checkout_request_id' => 'CHECKOUT-001',
            'account_reference' => 'FEES-001',
            'amount' => 1500.00,
            'phone_number' => '254712345678',
            'status' => 'successful',
            'gateway_response' => 'ABC123',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('mpesa_callbacks')->insert([
            'school_id' => $school->id,
            'callback_type' => 'STK_RESULT',
            'merchant_request_id' => 'MERCHANT-001',
            'checkout_request_id' => 'CHECKOUT-001',
            'mpesa_receipt_number' => 'ABC123',
            'result_code' => '0',
            'result_desc' => 'The service request is processed successfully.',
            'amount' => 1500.00,
            'phone_number' => '254712345678',
            'raw_payload' => json_encode(['ResultCode' => 0]),
            'is_processed' => true,
            'processing_notes' => 'Allocated via STK tracking.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($accountant)
            ->getJson('/api/v1/finance/payments/mpesa-status');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.merchant_request_id', 'MERCHANT-001')
            ->assertJsonPath('data.0.status', 'successful')
            ->assertJsonPath('data.0.callback_result_code', '0')
            ->assertJsonPath('data.0.callback_processed', true);
    }
}
