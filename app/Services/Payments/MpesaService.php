<?php

// Route Path: Service Class (Not directly routable. Consumed by MpesaController and STK API endpoints)
// Path: app/Services/Payments/MpesaService.php

namespace App\Services\Payments;

use App\Models\Student;
use App\Services\Finance\PaymentAllocationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class MpesaService
{
    public function __construct(
        protected PaymentAllocationService $paymentService
    ) {}

    protected function mapGatewayToPaymentMethod(string $gatewayName): string
    {
        return match ($gatewayName) {
            'mpesa' => 'mpesa',
            'coop_bank', 'coop_400222' => 'bank_transfer',
            default => 'online',
        };
    }

    /**
     * Generate Daraja OAuth Access Token using school-specific credentials.
     */
    protected function getAccessToken(int $schoolId): string
    {
        $config = DB::table('payment_gateway_configs')
            ->where('school_id', $schoolId)
            ->where('gateway_name', 'mpesa')
            ->where('is_active', true)
            ->first();

        if (!$config || !$config->consumer_key || !$config->consumer_secret) {
            throw new Exception("M-Pesa credentials not configured for this school.");
        }

        $url = $config->environment === 'production' 
            ? 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials'
            : 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';

        $credentials = base64_encode($config->consumer_key . ':' . $config->consumer_secret);

        $response = Http::withHeaders(['Authorization' => 'Basic ' . $credentials])->get($url);

        if (!$response->successful()) {
            throw new Exception("Failed to generate Daraja access token: " . $response->body());
        }

        return $response->json('access_token');
    }

    /**
     * Initiate an STK Push (Lipa Na M-Pesa Online) to a parent's phone.
     */
    public function initiateStkPush(int $schoolId, int $studentId, string $phone, string $amount, string $reference): array
    {
        $config = DB::table('payment_gateway_configs')
            ->where('school_id', $schoolId)
            ->where('gateway_name', 'mpesa')
            ->first();

        $token = $this->getAccessToken($schoolId);
        $timestamp = date('YmdHis');
        $password = base64_encode($config->shortcode . $config->passkey . $timestamp);

        $url = $config->environment === 'production'
            ? 'https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest'
            : 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest';

        // Format phone to 2547XXXXXXXX
        $formattedPhone = preg_replace('/^(?:\+?254|0)?(7\d{8}|1\d{8})$/', '254$1', $phone);

        $payload = [
            'BusinessShortCode' => $config->shortcode,
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            'TransactionType'   => 'CustomerPayBillOnline',
            'Amount'            => ceil($amount), // Daraja only accepts integers
            'PartyA'            => $formattedPhone,
            'PartyB'            => $config->shortcode,
            'PhoneNumber'       => $formattedPhone,
            'CallBackURL'       => route('api.mpesa.stk-callback'), // Ensure this is absolute and exposed via ngrok/production domain
            'AccountReference'  => substr($reference, 0, 12),
            'TransactionDesc'   => "School Fees Payment"
        ];

        $response = Http::withToken($token)->post($url, $payload);

        if (!$response->successful()) {
            throw new Exception("STK Push failed: " . $response->body());
        }

        $data = $response->json();

        // Track the pending transaction internally
        DB::table('payment_gateway_transactions')->insert([
            'school_id'           => $schoolId,
            'student_id'          => $studentId,
            'gateway_name'        => $config->gateway_name ?? 'mpesa',
            'transaction_type'    => 'STK_PUSH',
            'merchant_request_id' => $data['MerchantRequestID'],
            'checkout_request_id' => $data['CheckoutRequestID'],
            'account_reference'   => $reference,
            'amount'              => $amount,
            'phone_number'        => $formattedPhone,
            'status'              => 'pending',
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        return $data;
    }

    /**
     * Process a successful STK Callback from MpesaController.
     */
    public function processStkResult(int $callbackId, string $merchantRequestId, string $amount, string $receipt, string $phone): void
    {
        DB::transaction(function () use ($callbackId, $merchantRequestId, $amount, $receipt, $phone) {
            $transaction = DB::table('payment_gateway_transactions')
                ->where('merchant_request_id', $merchantRequestId)
                ->first();

            if (!$transaction || $transaction->status === 'successful') return;

            // 1. Mark Gateway Transaction as successful
            DB::table('payment_gateway_transactions')
                ->where('id', $transaction->id)
                ->update(['status' => 'successful', 'gateway_response' => $receipt, 'updated_at' => now()]);

            $paymentMethod = $this->mapGatewayToPaymentMethod($transaction->gateway_name ?? 'mpesa');

            // 2. Pass to PaymentAllocationService to generate real Sub-Ledger Payment & Receipt
            $paymentData = [
                'student_id'              => $transaction->student_id,
                'amount'                  => $amount,
                'payment_method'          => $paymentMethod,
                'reference_number'        => $receipt,
                'external_transaction_id' => $receipt,
                'payer_phone'             => $phone,
                'auto_allocate'           => true,
            ];

            // Use system user (ID: 1) for automated gateway transactions
            $this->paymentService->collectAndAllocate($paymentData, $transaction->school_id, 1);

            // 3. Mark Callback as Processed
            DB::table('mpesa_callbacks')->where('id', $callbackId)->update(['is_processed' => true, 'processing_notes' => 'Allocated via STK tracking.']);
        });
    }

    /**
     * Mark an STK Push as failed (User cancelled, insufficient funds, timeout).
     */
    public function markStkFailed(string $merchantRequestId, string $reason): void
    {
        DB::table('payment_gateway_transactions')
            ->where('merchant_request_id', $merchantRequestId)
            ->update(['status' => 'failed', 'gateway_response' => $reason, 'updated_at' => now()]);
    }

    /**
     * Process a C2B Confirmation (Manual Paybill payment).
     * If AccountRef matches a student, allocate. If not, push to Suspense Reconciliation.
     */
    public function processC2bConfirmation(int $callbackId, string $receipt, string $amount, string $phone, string $accountRef): void
    {
        $callback = DB::table('mpesa_callbacks')->find($callbackId);
        $schoolId = $callback->school_id; // Assumes your Webhook route extracted school_id from URL/Paybill mappings
        $gatewayName = $callback->gateway_name ?? 'mpesa';

        if (!$schoolId) {
            // Attempt a fallback match by student admission number across all schools.
            $fallbackStudent = Student::where('admission_no', $accountRef)->first();
            if ($fallbackStudent) {
                $schoolId = $fallbackStudent->school_id;
                Log::channel('mpesa')->warning("Fallback school_id resolved from student admission number.", ['callback_id' => $callbackId, 'school_id' => $schoolId]);
            }
        }

        if (!$schoolId) {
            Log::channel('mpesa')->error("Orphaned C2B Callback. No school_id found.", ['callback_id' => $callbackId]);
            return;
        }

        DB::transaction(function () use ($callbackId, $schoolId, $gatewayName, $receipt, $amount, $phone, $accountRef) {
            // Attempt to find student by Admission Number matching the AccountReference
            $student = Student::where('school_id', $schoolId)
                ->where('admission_no', $accountRef)
                ->first();

            $paymentMethod = $this->mapGatewayToPaymentMethod($gatewayName);

            if ($student) {
                // PERFECT MATCH -> Allocate directly
                $paymentData = [
                    'student_id'              => $student->id,
                    'amount'                  => $amount,
                    'payment_method'          => $paymentMethod,
                    'reference_number'        => $receipt,
                    'external_transaction_id' => $receipt,
                    'payer_phone'             => $phone,
                    'auto_allocate'           => true,
                ];

                $this->paymentService->collectAndAllocate($paymentData, $schoolId, 1);

                DB::table('mpesa_callbacks')->where('id', $callbackId)->update([
                    'is_processed' => true, 
                    'processing_notes' => "Auto-allocated to Student ID: {$student->id}"
                ]);
            } else {
                // NO MATCH -> Send to Suspense Account (payment_reconciliation_items)
                DB::table('payment_reconciliation_items')->insert([
                    'school_id'            => $schoolId,
                    'mpesa_callback_id'    => $callbackId,
                    'gateway_name'         => $gatewayName,
                    'amount'               => $amount,
                    'mpesa_receipt_number' => $receipt,
                    'account_reference'    => $accountRef,
                    'phone_number'         => $phone,
                    'status'               => 'unmatched',
                    'created_at'           => now(),
                    'updated_at'           => now(),
                ]);

                DB::table('mpesa_callbacks')->where('id', $callbackId)->update([
                    'is_processed' => true, 
                    'processing_notes' => 'Pushed to Suspense Account for manual reconciliation.'
                ]);
            }
        });
    }
}