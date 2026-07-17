<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\ClassRoom;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\School;
use App\Models\Student;
use App\Services\Finance\StudentBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentBalanceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_recalculate_persists_correct_totals_for_statement(): void
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
            'admission_no' => 'ADM-002',
            'roll_number' => '2',
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

        Invoice::create([
            'school_id' => $school->id,
            'student_id' => $student->id,
            'session_id' => $session->id,
            'class_id' => $classRoom->id,
            'invoice_number' => 'INV-2001',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addWeek()->toDateString(),
            'currency' => 'KES',
            'subtotal' => 5000.00,
            'discount_total' => 0.00,
            'waiver_total' => 0.00,
            'penalty_total' => 0.00,
            'total' => 5000.00,
            'amount_paid' => 1200.00,
            'balance' => 3800.00,
            'status' => 'partially_paid',
            'posted_to_ledger' => false,
        ]);

        Payment::create([
            'school_id' => $school->id,
            'student_id' => $student->id,
            'payment_number' => 'PMT-2025-00001',
            'payment_method' => 'cash',
            'currency' => 'KES',
            'amount' => 1200.00,
            'payment_date' => now(),
            'reference_number' => null,
            'external_transaction_id' => null,
            'payer_name' => null,
            'payer_phone' => null,
            'status' => 'successful',
            'received_by' => null,
            'posted_to_ledger' => false,
        ]);

        $service = app(StudentBalanceService::class);
        $balance = $service->recalculate($student);

        $this->assertSame('5000.00', (string) $balance->invoiced_total);
        $this->assertSame('1200.00', (string) $balance->paid_total);
        $this->assertSame('3800.00', (string) $balance->balance);
    }

    public function test_recalculate_tracks_current_term_overpayment_credit(): void
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
            'name' => 'Grade 2-A',
            'grade' => 2,
            'section' => 'A',
            'capacity' => 40,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $student = Student::create([
            'school_id' => $school->id,
            'session_id' => $session->id,
            'class_id' => $classRoom->id,
            'admission_no' => 'ADM-003',
            'roll_number' => '3',
            'first_name' => 'Over',
            'last_name' => 'Pay',
            'date_of_birth' => now()->subYears(10)->toDateString(),
            'gender' => 'male',
            'address' => '789 Test Rd',
            'status' => 'active',
            'admission_date' => now()->subYear()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Invoice::create([
            'school_id' => $school->id,
            'student_id' => $student->id,
            'session_id' => $session->id,
            'class_id' => $classRoom->id,
            'invoice_number' => 'INV-3001',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addWeek()->toDateString(),
            'currency' => 'KES',
            'subtotal' => 5000.00,
            'discount_total' => 0.00,
            'waiver_total' => 0.00,
            'penalty_total' => 0.00,
            'total' => 5000.00,
            'amount_paid' => 0.00,
            'balance' => 5000.00,
            'status' => 'issued',
            'posted_to_ledger' => false,
        ]);

        Invoice::create([
            'school_id' => $school->id,
            'student_id' => $student->id,
            'session_id' => $session->id,
            'class_id' => $classRoom->id,
            'invoice_number' => 'INV-3002',
            'issue_date' => now()->subMonths(2)->toDateString(),
            'due_date' => now()->subMonths(2)->toDateString(),
            'currency' => 'KES',
            'subtotal' => 3000.00,
            'discount_total' => 0.00,
            'waiver_total' => 0.00,
            'penalty_total' => 0.00,
            'total' => 3000.00,
            'amount_paid' => 0.00,
            'balance' => 3000.00,
            'status' => 'issued',
            'posted_to_ledger' => false,
        ]);

        Payment::create([
            'school_id' => $school->id,
            'student_id' => $student->id,
            'payment_number' => 'PMT-2025-00002',
            'payment_method' => 'mpesa',
            'currency' => 'KES',
            'amount' => 9000.00,
            'payment_date' => now(),
            'reference_number' => null,
            'external_transaction_id' => null,
            'payer_name' => null,
            'payer_phone' => null,
            'status' => 'successful',
            'received_by' => null,
            'posted_to_ledger' => false,
        ]);

        $service = app(StudentBalanceService::class);
        $balance = $service->recalculate($student);

        $this->assertSame('8000.00', (string) $balance->invoiced_total);
        $this->assertSame('8000.00', (string) $balance->paid_total);
        $this->assertSame('0.00', (string) $balance->balance);
        $this->assertSame('1000.00', (string) $balance->credit_total);
    }
}
