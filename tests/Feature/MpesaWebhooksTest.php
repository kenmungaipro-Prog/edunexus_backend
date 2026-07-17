<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class MpesaWebhooksTest extends TestCase
{
    use RefreshDatabase;

    public function test_stk_callback_logs_and_accepts()
    {
        $payload = [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'MID123',
                    'CheckoutRequestID' => 'CID123',
                    'ResultCode' => 0,
                    'ResultDesc' => 'Success',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => 100],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'ABC123'],
                            ['Name' => 'PhoneNumber', 'Value' => '254712345678'],
                        ]
                    ]
                ]
            ]
        ];

        $response = $this->postJson('/api/v1/payments/mpesa/stk-callback', $payload);

        $response->assertStatus(200)->assertJson(['ResultCode' => 0]);

        $this->assertDatabaseHas('mpesa_callbacks', [
            'checkout_request_id' => 'CID123',
            'merchant_request_id' => 'MID123'
        ]);
    }

    public function test_c2b_confirmation_logs_and_accepts()
    {
        $payload = [
            'TransID' => 'TID123',
            'TransAmount' => 250.00,
            'MSISDN' => '254712345678',
            'BillRefNumber' => 'UNKNOWN_REF'
        ];

        $response = $this->postJson('/api/v1/payments/mpesa/c2b-confirmation', $payload);

        $response->assertStatus(200)->assertJson(['ResultCode' => 0]);

        $this->assertDatabaseHas('mpesa_callbacks', [
            'mpesa_receipt_number' => 'TID123'
        ]);
    }
}
