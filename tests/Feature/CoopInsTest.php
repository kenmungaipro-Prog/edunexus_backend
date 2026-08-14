<?php

use App\Models\AcademicSession;
use App\Models\School;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

test('coop INS accepts a valid payment and persists the transaction', function () {
    $school = School::factory()->create();

    $session = AcademicSession::factory()->create([
        'school_id' => $school->id,
        'is_current' => true,
    ]);

    $student = Student::factory()->create([
        'school_id' => $school->id,
        'session_id' => $session->id,
        'admission_no' => 'ADM001',
    ]);

    DB::table('payment_gateway_configs')->insert([
        'school_id' => $school->id,
        'gateway_name' => 'coop_400222',
        'environment' => 'sandbox',
        'shortcode' => '400222',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $payload = [
        'MessageReference' => 'MSG-INS-001',
        'MessageDateTime' => '2026-08-11T08:30:00',
        'TransactionId' => 'TXN-INS-001',
        'PaymentRef' => 'PAY-INS-001',
        'Amount' => '15000.00',
        'AccountNumber' => '400222',
        'EventType' => 'CREDIT',
        'Narration' => 'School fees payment',
        'TransactionDate' => '2026-08-11',
        'MSISDN' => '254712345678',
        'CustMemo' => [
            'CustMemoLine1' => '400222#ADM001',
        ],
    ];

    $response = $this->postJson(
        '/api/v1/payments/coop/ins',
        $payload
    );

    $response
        ->assertStatus(200)
        ->assertJson([
            'MessageReference' => 'MSG-INS-001',
            'MessageCode' => '0',
            'MessageDescription' => 'Acknowledged',
        ]);

    $this->assertDatabaseHas('mpesa_callbacks', [
        'callback_type' => 'INS',
        'gateway_name' => 'coop_400222',
        'mpesa_receipt_number' => 'PAY-INS-001',
        'amount' => '15000.00',
        'phone_number' => '254712345678',
        'account_reference' => 'ADM001',
        'school_id' => $school->id,
    ]);

    $this->assertDatabaseHas('payment_gateway_transactions', [
        'school_id' => $school->id,
        'student_id' => $student->id,
        'gateway_name' => 'coop_400222',
        'transaction_type' => 'INS',
        'account_reference' => 'ADM001',
        'amount' => '15000.00',
        'phone_number' => '254712345678',
        'status' => 'successful',
        'gateway_response' => 'PAY-INS-001',
    ]);
});

test('coop INS with missing payment identifiers does not create a meaningless transaction', function () {
    $school = School::factory()->create();

    DB::table('payment_gateway_configs')->insert([
        'school_id' => $school->id,
        'gateway_name' => 'coop_400222',
        'environment' => 'sandbox',
        'shortcode' => '400222',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $payload = [
        'MessageReference' => 'MSG-INS-NOID-001',
        'MessageDateTime' => '2026-08-14T10:00:00',
        // No TransactionId or PaymentRef
        'Amount' => '5000.00',
        'AccountNumber' => '400222',
        'MSISDN' => '254711222333',
    ];

    $response = $this->postJson('/api/v1/payments/coop/ins', $payload);
    $response->assertStatus(200)->assertJson(['MessageCode' => '0']);

    // No normalized transaction or payment should be created without a meaningful receipt
    $this->assertDatabaseMissing('payment_gateway_transactions', [
        'gateway_name' => 'coop_400222',
    ]);
    $this->assertDatabaseMissing('payments', [
        'school_id' => $school->id,
    ]);
});

test('coop INS with missing amount is handled safely (zero amount)', function () {
    $school = School::factory()->create();

    DB::table('payment_gateway_configs')->insert([
        'school_id' => $school->id,
        'gateway_name' => 'coop_400222',
        'environment' => 'sandbox',
        'shortcode' => '400222',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $payload = [
        'MessageReference' => 'MSG-INS-ZERO-001',
        'MessageDateTime' => '2026-08-14T10:30:00',
        'TransactionId' => 'TXN-INS-ZERO-001',
        'PaymentRef' => 'PAY-INS-ZERO-001',
        // Missing Amount -> controller defaults to '0.00'
        'AccountNumber' => '400222',
        'MSISDN' => '254711222334',
    ];

    $response = $this->postJson('/api/v1/payments/coop/ins', $payload);
    $response->assertStatus(200)->assertJson(['MessageCode' => '0']);

    // Callback may be recorded but no transaction/payment should be created for zero amount
    $this->assertDatabaseHas('mpesa_callbacks', [
        'callback_type' => 'INS',
        'mpesa_receipt_number' => 'PAY-INS-ZERO-001',
        'amount' => '0.00',
    ]);

    $this->assertDatabaseMissing('payment_gateway_transactions', [
        'gateway_response' => 'PAY-INS-ZERO-001',
    ]);
});

test('coop INS with missing CustMemo routes payment to reconciliation', function () {
    $school = School::factory()->create();

    DB::table('payment_gateway_configs')->insert([
        'school_id' => $school->id,
        'gateway_name' => 'coop_400222',
        'environment' => 'sandbox',
        'shortcode' => '400222',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $payload = [
        'MessageReference' => 'MSG-INS-NOMEMO-001',
        'MessageDateTime' => '2026-08-14T11:00:00',
        'TransactionId' => 'TXN-INS-NOMEMO-001',
        'PaymentRef' => 'PAY-INS-NOMEMO-001',
        'Amount' => '7000.00',
        'AccountNumber' => '400222',
        'MSISDN' => '254711222335',
        // No CustMemo provided
    ];

    $response = $this->postJson('/api/v1/payments/coop/ins', $payload);
    $response->assertStatus(200)->assertJson(['MessageCode' => '0']);

    // Since no student ref, ensure transaction exists but student_id is null
    $this->assertDatabaseHas('payment_gateway_transactions', [
        'gateway_response' => 'PAY-INS-NOMEMO-001',
        'student_id' => null,
        'school_id' => $school->id,
    ]);

    // And a reconciliation item is created (unmatched)
    $this->assertDatabaseHas('payment_reconciliation_items', [
        'mpesa_receipt_number' => 'PAY-INS-NOMEMO-001',
        'school_id' => $school->id,
        'status' => 'unmatched',
    ]);
});

test('coop INS with missing or inactive gateway config is not assigned to a school', function () {
    // Do NOT insert payment_gateway_configs

    $payload = [
        'MessageReference' => 'MSG-INS-NOGATE-001',
        'MessageDateTime' => '2026-08-14T11:30:00',
        'TransactionId' => 'TXN-INS-NOGATE-001',
        'PaymentRef' => 'PAY-INS-NOGATE-001',
        'Amount' => '9500.00',
        'AccountNumber' => '400222',
        'MSISDN' => '254711222336',
        'CustMemo' => [
            'CustMemoLine1' => '400222#ADM999',
        ],
    ];

    $response = $this->postJson('/api/v1/payments/coop/ins', $payload);
    $response->assertStatus(200)->assertJson(['MessageCode' => '0']);

    // mpesa_callbacks should be recorded but without a school assigned
    $this->assertDatabaseHas('mpesa_callbacks', [
        'mpesa_receipt_number' => 'PAY-INS-NOGATE-001',
        'school_id' => null,
    ]);

    // And no normalized gateway transaction tied to a school should exist
    $this->assertDatabaseMissing('payment_gateway_transactions', [
        'gateway_response' => 'PAY-INS-NOGATE-001',
    ]);
});

test('coop INS idempotency is receipt-level even with different message references', function () {
    $school = School::factory()->create();

    DB::table('payment_gateway_configs')->insert([
        'school_id' => $school->id,
        'gateway_name' => 'coop_400222',
        'environment' => 'sandbox',
        'shortcode' => '400222',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $base = [
        'TransactionId' => 'TXN-INS-IDEMP-001',
        'PaymentRef' => 'PAY-INS-IDEMP-001',
        'Amount' => '11000.00',
        'AccountNumber' => '400222',
        'MSISDN' => '254711222337',
        'CustMemo' => [ 'CustMemoLine1' => '400222#ADMIDEMP' ],
    ];

    $first = array_merge($base, [
        'MessageReference' => 'MSG-INS-IDEMP-A',
        'MessageDateTime' => '2026-08-14T12:00:00',
    ]);

    $second = array_merge($base, [
        'MessageReference' => 'MSG-INS-IDEMP-B',
        'MessageDateTime' => '2026-08-14T12:00:10',
    ]);

    $this->postJson('/api/v1/payments/coop/ins', $first)->assertStatus(200);
    $this->postJson('/api/v1/payments/coop/ins', $second)->assertStatus(200);

    // Only one callback/transaction should exist keyed by receipt
    expect(DB::table('mpesa_callbacks')->where('mpesa_receipt_number', 'PAY-INS-IDEMP-001')->count())->toBe(1);
    expect(DB::table('payment_gateway_transactions')->where('gateway_response', 'PAY-INS-IDEMP-001')->count())->toBe(1);
});

test('coop INS acknowledgement format and exception handling still returns ack', function () {
    $school = School::factory()->create();

    DB::table('payment_gateway_configs')->insert([
        'school_id' => $school->id,
        'gateway_name' => 'coop_400222',
        'environment' => 'sandbox',
        'shortcode' => '400222',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Make Bus::dispatchSync throw to simulate processing exception
    Bus::shouldReceive('dispatchSync')->andThrow(new \Exception('processing failure'));

    $payload = [
        'MessageReference' => 'MSG-INS-EXC-001',
        'MessageDateTime' => '2026-08-14T12:30:00',
        'TransactionId' => 'TXN-INS-EXC-001',
        'PaymentRef' => 'PAY-INS-EXC-001',
        'Amount' => '6000.00',
        'AccountNumber' => '400222',
        'MSISDN' => '254711222338',
        'CustMemo' => [ 'CustMemoLine1' => '400222#ADMEXC' ],
    ];

    $response = $this->postJson('/api/v1/payments/coop/ins', $payload);

    // Acknowledgement must still be returned
    $response->assertStatus(200)->assertJsonStructure([
        'MessageReference', 'MessageDateTime', 'MessageCode', 'MessageDescription'
    ])->assertJson([
        'MessageReference' => 'MSG-INS-EXC-001',
        'MessageCode' => '0',
        'MessageDescription' => 'Acknowledged',
    ]);

    // Ensure the raw callback was still persisted despite processing failure
    $this->assertDatabaseHas('mpesa_callbacks', [
        'mpesa_receipt_number' => 'PAY-INS-EXC-001',
    ]);
});

test('coop INS accepts payment when student reference is unknown', function () {
    $school = School::factory()->create();

    DB::table('payment_gateway_configs')->insert([
        'school_id' => $school->id,
        'gateway_name' => 'coop_400222',
        'environment' => 'sandbox',
        'shortcode' => '400222',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $payload = [
        'MessageReference' => 'MSG-INS-UNKNOWN-001',
        'MessageDateTime' => '2026-08-11T09:00:00',
        'TransactionId' => 'TXN-INS-UNKNOWN-001',
        'PaymentRef' => 'PAY-INS-UNKNOWN-001',
        'Amount' => '8500.00',
        'AccountNumber' => '400222',
        'EventType' => 'CREDIT',
        'Narration' => 'Unknown student payment',
        'TransactionDate' => '2026-08-11',
        'MSISDN' => '254700000001',
        'CustMemo' => [
            'CustMemoLine1' => '400222#DOES-NOT-EXIST',
        ],
    ];

    $response = $this->postJson(
        '/api/v1/payments/coop/ins',
        $payload
    );

    $response
        ->assertStatus(200)
        ->assertJson([
            'MessageReference' => 'MSG-INS-UNKNOWN-001',
            'MessageCode' => '0',
            'MessageDescription' => 'Acknowledged',
        ]);

    $this->assertDatabaseHas('mpesa_callbacks', [
        'callback_type' => 'INS',
        'gateway_name' => 'coop_400222',
        'mpesa_receipt_number' => 'PAY-INS-UNKNOWN-001',
        'account_reference' => 'DOES-NOT-EXIST',
        'school_id' => $school->id,
    ]);

    $this->assertDatabaseHas('payment_gateway_transactions', [
        'school_id' => $school->id,
        'student_id' => null,
        'gateway_name' => 'coop_400222',
        'transaction_type' => 'INS',
        'account_reference' => 'DOES-NOT-EXIST',
        'gateway_response' => 'PAY-INS-UNKNOWN-001',
        'status' => 'successful',
    ]);
});
test('coop INS parses space separated CustMemoLine1 student reference', function () {
    $school = School::factory()->create();

    $session = AcademicSession::factory()->create([
        'school_id' => $school->id,
        'is_current' => true,
    ]);

    $student = Student::factory()->create([
        'school_id' => $school->id,
        'session_id' => $session->id,
        'admission_no' => 'ADM002',
    ]);

    DB::table('payment_gateway_configs')->insert([
        'school_id' => $school->id,
        'gateway_name' => 'coop_400222',
        'environment' => 'sandbox',
        'shortcode' => '400222',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $payload = [
        'MessageReference' => 'MSG-INS-SPACE-001',
        'MessageDateTime' => '2026-08-11T09:30:00',
        'TransactionId' => 'TXN-INS-SPACE-001',
        'PaymentRef' => 'PAY-INS-SPACE-001',
        'Amount' => '12000.00',
        'AccountNumber' => '400222',
        'EventType' => 'CREDIT',
        'Narration' => 'School fees payment',
        'TransactionDate' => '2026-08-11',
        'MSISDN' => '254711111111',
        'CustMemo' => [
            'CustMemoLine1' => '728210595 ADM002',
        ],
    ];

    $response = $this->postJson(
        '/api/v1/payments/coop/ins',
        $payload
    );

    $response
        ->assertStatus(200)
        ->assertJson([
            'MessageReference' => 'MSG-INS-SPACE-001',
            'MessageCode' => '0',
            'MessageDescription' => 'Acknowledged',
        ]);

    $this->assertDatabaseHas('mpesa_callbacks', [
        'callback_type' => 'INS',
        'gateway_name' => 'coop_400222',
        'mpesa_receipt_number' => 'PAY-INS-SPACE-001',
        'account_reference' => 'ADM002',
        'school_id' => $school->id,
    ]);

    $this->assertDatabaseHas('payment_gateway_transactions', [
        'school_id' => $school->id,
        'student_id' => $student->id,
        'gateway_name' => 'coop_400222',
        'transaction_type' => 'INS',
        'account_reference' => 'ADM002',
        'amount' => '12000.00',
        'gateway_response' => 'PAY-INS-SPACE-001',
        'status' => 'successful',
    ]);
});

test('coop INS auto allocates payment to student when reference matches', function () {
    $school = School::factory()->create();

    $session = AcademicSession::factory()->create([
        'school_id' => $school->id,
        'is_current' => true,
    ]);

    $student = Student::factory()->create([
        'school_id' => $school->id,
        'session_id' => $session->id,
        'admission_no' => 'ADM002',
    ]);

    DB::table('payment_gateway_configs')->insert([
        'school_id' => $school->id,
        'gateway_name' => 'coop_400222',
        'environment' => 'sandbox',
        'shortcode' => '400222',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $payload = [
        'MessageReference' => 'MSG-INS-002',
        'MessageDateTime' => '2026-08-14T08:30:00',
        'TransactionId' => 'TXN-INS-002',
        'PaymentRef' => 'PAY-INS-002',
        'Amount' => '15000.00',
        'AccountNumber' => '400222',
        'EventType' => 'CREDIT',
        'Narration' => 'School fees payment',
        'TransactionDate' => '2026-08-14',
        'MSISDN' => '254712345678',
        'CustMemo' => [
            'CustMemoLine1' => '400222#ADM002',
        ],
    ];

    $response = $this->postJson(
        '/api/v1/payments/coop/ins',
        $payload
    );

    $response
        ->assertStatus(200)
        ->assertJson([
            'MessageCode' => '0',
            'MessageDescription' => 'Acknowledged',
        ]);

    $this->assertDatabaseHas('payments', [
        'school_id' => $school->id,
        'student_id' => $student->id,
        'amount' => '15000.00',
    ]);

    $this->assertDatabaseHas('mpesa_callbacks', [
        'callback_type' => 'INS',
        'gateway_name' => 'coop_400222',
        'mpesa_receipt_number' => 'PAY-INS-002',
        'is_processed' => true,
    ]);
});

test('coop INS sends unmatched payment to reconciliation', function () {
    $school = School::factory()->create();

    DB::table('payment_gateway_configs')->insert([
        'school_id' => $school->id,
        'gateway_name' => 'coop_400222',
        'environment' => 'sandbox',
        'shortcode' => '400222',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $payload = [
        'MessageReference' => 'MSG-INS-003',
        'MessageDateTime' => '2026-08-14T08:30:00',
        'TransactionId' => 'TXN-INS-003',
        'PaymentRef' => 'PAY-INS-003',
        'Amount' => '8500.00',
        'AccountNumber' => '400222',
        'EventType' => 'CREDIT',
        'Narration' => 'Unknown student payment',
        'MSISDN' => '254712345678',
        'CustMemo' => [
            'CustMemoLine1' => '400222#UNKNOWN999',
        ],
    ];

    $response = $this->postJson(
        '/api/v1/payments/coop/ins',
        $payload
    );

    $response
        ->assertStatus(200)
        ->assertJson([
            'MessageCode' => '0',
            'MessageDescription' => 'Acknowledged',
        ]);

    $this->assertDatabaseHas('payment_reconciliation_items', [
        'school_id' => $school->id,
        'gateway_name' => 'coop_400222',
        'amount' => '8500.00',
        'mpesa_receipt_number' => 'PAY-INS-003',
        'account_reference' => 'UNKNOWN999',
        'phone_number' => '254712345678',
        'status' => 'unmatched',
    ]);

    $this->assertDatabaseHas('mpesa_callbacks', [
        'callback_type' => 'INS',
        'gateway_name' => 'coop_400222',
        'mpesa_receipt_number' => 'PAY-INS-003',
        'is_processed' => true,
    ]);
});

test('coop INS does not duplicate the same payment notification', function () {
    $school = School::factory()->create();

    $session = AcademicSession::factory()->create([
        'school_id' => $school->id,
        'is_current' => true,
    ]);

    $student = Student::factory()->create([
        'school_id' => $school->id,
        'session_id' => $session->id,
        'admission_no' => 'ADM003',
    ]);

    DB::table('payment_gateway_configs')->insert([
        'school_id' => $school->id,
        'gateway_name' => 'coop_400222',
        'environment' => 'sandbox',
        'shortcode' => '400222',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $payload = [
        'MessageReference' => 'MSG-INS-DUP-001',
        'MessageDateTime' => '2026-08-14T09:00:00',
        'TransactionId' => 'TXN-INS-DUP-001',
        'PaymentRef' => 'PAY-INS-DUP-001',
        'Amount' => '10000.00',
        'AccountNumber' => '400222',
        'EventType' => 'CREDIT',
        'Narration' => 'Duplicate test payment',
        'TransactionDate' => '2026-08-14',
        'MSISDN' => '254712345678',
        'CustMemo' => [
            'CustMemoLine1' => '400222#ADM003',
        ],
    ];

    // First notification
    $firstResponse = $this->postJson(
        '/api/v1/payments/coop/ins',
        $payload
    );

    $firstResponse
        ->assertStatus(200)
        ->assertJson([
            'MessageCode' => '0',
            'MessageDescription' => 'Acknowledged',
        ]);

    // Second notification: exact same bank notification
    $secondResponse = $this->postJson(
        '/api/v1/payments/coop/ins',
        $payload
    );

    $secondResponse
        ->assertStatus(200)
        ->assertJson([
            'MessageCode' => '0',
            'MessageDescription' => 'Acknowledged',
        ]);

    // Only ONE raw callback should exist
    expect(
        DB::table('mpesa_callbacks')
            ->where('callback_type', 'INS')
            ->where('gateway_name', 'coop_400222')
            ->where('mpesa_receipt_number', 'PAY-INS-DUP-001')
            ->count()
    )->toBe(1);

    // Only ONE normalized gateway transaction should exist
    expect(
        DB::table('payment_gateway_transactions')
            ->where('school_id', $school->id)
            ->where('gateway_name', 'coop_400222')
            ->where('gateway_response', 'PAY-INS-DUP-001')
            ->count()
    )->toBe(1);

    // Only ONE actual payment should exist
    expect(
        DB::table('payments')
            ->where('school_id', $school->id)
            ->where('student_id', $student->id)
            ->where('external_transaction_id', 'PAY-INS-DUP-001')
            ->count()
    )->toBe(1);
});