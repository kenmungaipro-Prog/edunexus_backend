<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\ClassRoom;
use App\Models\FinanceStatuses;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentControllerTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected AcademicSession $session;
    protected ClassRoom $classRoom;
    protected User $accountant;
    protected Student $student;
    protected Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create([
            'name' => 'Test School',
            'address' => '123 Test Lane',
            'phone' => '254700000000',
            'email' => 'school@test.example',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->session = AcademicSession::create([
            'school_id' => $this->school->id,
            'name' => '2025',
            'is_current' => true,
            'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->classRoom = ClassRoom::create([
            'school_id' => $this->school->id,
            'session_id' => $this->session->id,
            'name' => 'Grade 1-A',
            'grade' => 1,
            'section' => 'A',
            'capacity' => 40,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->accountant = User::create([
            'school_id' => $this->school->id,
            'name' => 'Accountant User',
            'email' => 'accountant@test.example',
            'password' => bcrypt('password'),
            'role' => 'accountant',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->student = Student::create([
            'school_id' => $this->school->id,
            'session_id' => $this->session->id,
            'class_id' => $this->classRoom->id,
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

        $this->invoice = Invoice::create([
            'school_id' => $this->school->id,
            'student_id' => $this->student->id,
            'session_id' => $this->session->id,
            'class_id' => $this->classRoom->id,
            'invoice_number' => 'INV-1001',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addWeek()->toDateString(),
            'currency' => 'KES',
            'subtotal' => 200.00,
            'discount_total' => 0.00,
            'waiver_total' => 0.00,
            'penalty_total' => 0.00,
            'total' => 200.00,
            'amount_paid' => 0.00,
            'balance' => 200.00,
            'status' => 'issued',
            'posted_to_ledger' => false,
        ]);
    }

    public function test_accountant_can_create_payment_and_generate_receipt()
    {
        $response = $this->actingAs($this->accountant)
            ->postJson('/api/v1/finance/payments', [
                'student_id' => $this->student->id,
                'amount' => 100.00,
                'payment_method' => 'cash',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.student_id', $this->student->id)
            ->assertJsonPath('data.amount', '100.00')
            ->assertJsonPath('data.receipt.receipt_number', fn ($receiptNumber) => is_string($receiptNumber));

        $this->assertDatabaseHas('payments', [
            'student_id' => $this->student->id,
            'school_id' => $this->school->id,
            'amount' => 100.00,
        ]);

        $this->assertDatabaseHas('payment_allocations', [
            'invoice_id' => $this->invoice->id,
            'amount_allocated' => 100.00,
        ]);

        $this->assertDatabaseHas('receipts', [
            'school_id' => $this->school->id,
        ]);

        $this->invoice->refresh();
        $this->assertSame('100.00', number_format($this->invoice->balance, 2, '.', ''));
        $this->assertSame('100.00', number_format($this->invoice->amount_paid, 2, '.', ''));
    }

    public function test_accountant_can_retrieve_payment_receipt()
    {
        $createResponse = $this->actingAs($this->accountant)
            ->postJson('/api/v1/finance/payments', [
                'student_id' => $this->student->id,
                'amount' => 100.00,
                'payment_method' => 'cash',
            ]);

        $createResponse->assertStatus(201);
        $paymentId = $createResponse->json('data.id');
        $receiptId = $createResponse->json('data.receipt.id');

        $receiptResponse = $this->actingAs($this->accountant)
            ->getJson("/api/v1/finance/payments/{$paymentId}/receipt");

        $receiptResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $receiptId)
            ->assertJsonPath('data.payment.id', $paymentId)
            ->assertJsonPath('data.receipt_number', fn ($receiptNumber) => is_string($receiptNumber));
    }

    public function test_accountant_can_allocate_payment_after_create_with_auto_allocate_false()
    {
        $secondInvoice = Invoice::create([
            'school_id' => $this->school->id,
            'student_id' => $this->student->id,
            'session_id' => $this->session->id,
            'class_id' => $this->classRoom->id,
            'invoice_number' => 'INV-1002',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addWeek()->toDateString(),
            'currency' => 'KES',
            'subtotal' => 120.00,
            'discount_total' => 0.00,
            'waiver_total' => 0.00,
            'penalty_total' => 0.00,
            'total' => 120.00,
            'amount_paid' => 0.00,
            'balance' => 120.00,
            'status' => 'issued',
            'posted_to_ledger' => false,
        ]);

        $createResponse = $this->actingAs($this->accountant)
            ->postJson('/api/v1/finance/payments', [
                'student_id' => $this->student->id,
                'amount' => 150.00,
                'payment_method' => 'cash',
                'auto_allocate' => false,
            ]);

        $createResponse->assertStatus(201);
        $paymentId = $createResponse->json('data.id');

        $allocateResponse = $this->actingAs($this->accountant)
            ->postJson("/api/v1/finance/payments/{$paymentId}/allocate");

        $allocateResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'fully_allocated');

        $this->assertDatabaseHas('payment_allocations', [
            'invoice_id' => $this->invoice->id,
            'amount_allocated' => 150.00,
        ]);

        $this->assertDatabaseMissing('payment_allocations', [
            'invoice_id' => $secondInvoice->id,
        ]);

        $this->invoice->refresh();
        $this->assertSame('50.00', number_format($this->invoice->balance, 2, '.', ''));
        $this->assertSame('150.00', number_format($this->invoice->amount_paid, 2, '.', ''));

        $secondInvoice->refresh();
        $this->assertSame('120.00', number_format($secondInvoice->balance, 2, '.', ''));
        $this->assertSame('0.00', number_format($secondInvoice->amount_paid, 2, '.', ''));
    }

    public function test_accountant_can_reverse_a_payment_and_restore_invoice_balance()
    {
        $createResponse = $this->actingAs($this->accountant)
            ->postJson('/api/v1/finance/payments', [
                'student_id' => $this->student->id,
                'amount' => 100.00,
                'payment_method' => 'cash',
            ]);

        $createResponse->assertStatus(201);
        $paymentId = $createResponse->json('data.id');

        $reverseResponse = $this->actingAs($this->accountant)
            ->postJson("/api/v1/finance/payments/{$paymentId}/reverse");

        $reverseResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'reversed');

        $this->invoice->refresh();
        $this->assertSame('200.00', number_format($this->invoice->balance, 2, '.', ''));
        $this->assertSame('0.00', number_format($this->invoice->amount_paid, 2, '.', ''));
    }

    public function test_accountant_can_refund_unallocated_payment_credit()
    {
        $createResponse = $this->actingAs($this->accountant)
            ->postJson('/api/v1/finance/payments', [
                'student_id' => $this->student->id,
                'amount' => 250.00,
                'payment_method' => 'cash',
                'auto_allocate' => false,
            ]);

        $createResponse->assertStatus(201);
        $paymentId = $createResponse->json('data.id');

        $allocateResponse = $this->actingAs($this->accountant)
            ->postJson("/api/v1/finance/payments/{$paymentId}/allocate");

        $allocateResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'partially_allocated');

        $refundResponse = $this->actingAs($this->accountant)
            ->postJson("/api/v1/finance/payments/{$paymentId}/refund");

        $refundResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.amount_refunded', '50.00')
            ->assertJsonPath('data.status', FinanceStatuses::PAYMENT_FULLY_ALLOCATED);

        $this->assertDatabaseHas('payments', [
            'id' => $paymentId,
            'amount' => 200.00,
            'status' => FinanceStatuses::PAYMENT_FULLY_ALLOCATED,
        ]);

        $this->assertDatabaseHas('payment_allocations', [
            'payment_id' => $paymentId,
            'invoice_id' => $this->invoice->id,
            'amount_allocated' => 200.00,
        ]);

        $this->invoice->refresh();
        $this->assertSame('0.00', number_format($this->invoice->balance, 2, '.', ''));
        $this->assertSame('200.00', number_format($this->invoice->amount_paid, 2, '.', ''));
    }

    public function test_invoice_issue_rolls_over_student_credit_to_new_invoice()
    {
        $createResponse = $this->actingAs($this->accountant)
            ->postJson('/api/v1/finance/payments', [
                'student_id' => $this->student->id,
                'amount' => 250.00,
                'payment_method' => 'cash',
                'auto_allocate' => true,
            ]);

        $createResponse->assertStatus(201);
        $paymentId = $createResponse->json('data.id');
        $paymentStatus = $createResponse->json('data.status');

        $this->assertSame('partially_allocated', $paymentStatus);

        $draftInvoice = Invoice::create([
            'school_id' => $this->school->id,
            'student_id' => $this->student->id,
            'session_id' => $this->session->id,
            'class_id' => $this->classRoom->id,
            'invoice_number' => 'INV-1002',
            'currency' => 'KES',
            'subtotal' => 100.00,
            'discount_total' => 0.00,
            'waiver_total' => 0.00,
            'penalty_total' => 0.00,
            'total' => 100.00,
            'amount_paid' => 0.00,
            'balance' => 100.00,
            'status' => 'draft',
            'posted_to_ledger' => false,
        ]);

        $issueResponse = $this->actingAs($this->accountant)
            ->postJson("/api/v1/finance/invoices/{$draftInvoice->id}/issue");

        $issueResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'issued');

        $draftInvoice->refresh();
        $this->assertSame('50.00', number_format($draftInvoice->balance, 2, '.', ''));
        $this->assertSame('50.00', number_format($draftInvoice->amount_paid, 2, '.', ''));

        $this->assertDatabaseHas('payments', [
            'id' => $paymentId,
            'status' => FinanceStatuses::PAYMENT_FULLY_ALLOCATED,
            'amount' => 250.00,
        ]);
    }
}
