<?php

// Route: POST /api/v1/payments/mpesa/stk-callback
// Route: POST /api/v1/payments/mpesa/c2b-validation
// Route: POST /api/v1/payments/mpesa/c2b-confirmation
// Path: app/Http/Controllers/Api/Payments/MpesaController.php

namespace App\Http\Controllers\Api\Payments;

use App\Http\Controllers\Controller;
use App\Services\Payments\MpesaService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Jobs\ProcessMpesaCallback;

class MpesaController extends Controller
{
    public function __construct(protected MpesaService $mpesaService) {}

    protected function resolveCallbackContext(?string $checkoutRequestId = null, ?string $merchantRequestId = null): array
    {
        $record = null;

        if ($checkoutRequestId && $merchantRequestId) {
            $record = DB::table('payment_gateway_transactions')
                ->where('checkout_request_id', $checkoutRequestId)
                ->orWhere('merchant_request_id', $merchantRequestId)
                ->first();
        } elseif ($checkoutRequestId) {
            $record = DB::table('payment_gateway_transactions')
                ->where('checkout_request_id', $checkoutRequestId)
                ->first();
        } elseif ($merchantRequestId) {
            $record = DB::table('payment_gateway_transactions')
                ->where('merchant_request_id', $merchantRequestId)
                ->first();
        }

        return [
            'school_id' => $record?->school_id ?? null,
            'gateway_name' => $record?->gateway_name ?? 'mpesa',
        ];
    }

    /**
     * Handle incoming STK Push (Lipa Na M-Pesa Online) Callbacks.
     */
    public function stkCallback(Request $request): JsonResponse
    {
        $payload = $request->all();
        Log::channel('mpesa')->info('STK Callback Received', ['payload' => $payload]);

        $body = $payload['Body']['stkCallback'] ?? null;
        if (!$body) {
            return response()->json(['ResultCode' => 1, 'ResultDesc' => 'Invalid payload structure.']);
        }

        $merchantRequestId = $body['MerchantRequestID'];
        $checkoutRequestId = $body['CheckoutRequestID'];
        $resultCode        = (string) $body['ResultCode'];
        $resultDesc        = $body['ResultDesc'];

        // Extract metadata if transaction was successful (ResultCode == 0)
        $amount = null;
        $receipt = null;
        $phone = null;

        if ($resultCode === '0' && isset($body['CallbackMetadata']['Item'])) {
            foreach ($body['CallbackMetadata']['Item'] as $item) {
                if ($item['Name'] === 'Amount') $amount = (string) $item['Value'];
                if ($item['Name'] === 'MpesaReceiptNumber') $receipt = $item['Value'];
                if ($item['Name'] === 'PhoneNumber') $phone = (string) $item['Value'];
            }
        }

        try {
            // 1. Immutable Raw Log & Idempotency Check
            $callback = DB::table('mpesa_callbacks')->where('checkout_request_id', $checkoutRequestId)->first();

            if ($callback && $callback->is_processed) {
                return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Callback already processed.']);
            }

            if (!$callback) {
                $context = $this->resolveCallbackContext($checkoutRequestId, $merchantRequestId);

                $callbackId = DB::table('mpesa_callbacks')->insertGetId([
                    'callback_type'        => 'STK_RESULT',
                    'gateway_name'         => $context['gateway_name'],
                    'merchant_request_id'  => $merchantRequestId,
                    'checkout_request_id'  => $checkoutRequestId,
                    'mpesa_receipt_number' => $receipt,
                    'result_code'          => $resultCode,
                    'result_desc'          => $resultDesc,
                    'amount'               => $amount,
                    'phone_number'         => $phone,
                    'school_id'            => $context['school_id'],
                    'raw_payload'          => json_encode($payload),
                    'created_at'           => now(),
                    'updated_at'           => now(),
                ]);
            } else {
                $callbackId = $callback->id;
            }

            // 2. Dispatch processing job (synchronous execution ensures callback handling during webhook requests)
            if ($resultCode === '0') {
                ProcessMpesaCallback::dispatchSync(callbackId: $callbackId, type: 'stk', merchantRequestId: $merchantRequestId, checkoutRequestId: $checkoutRequestId, amount: $amount, receipt: $receipt, phone: $phone);
            } else {
                ProcessMpesaCallback::dispatchSync(callbackId: $callbackId, type: 'stk', merchantRequestId: $merchantRequestId);
            }

            return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);

        } catch (\Exception $e) {
            Log::channel('mpesa')->error('STK Callback Processing Failed', ['error' => $e->getMessage()]);
            // Always return 0 to Safaricom so they stop retrying, but log the failure internally.
            return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted, but internal error occurred.']);
        }
    }

    /**
     * Handle C2B Paybill Validation (Optional but recommended for catching bad account numbers early).
     */
    public function c2bValidation(Request $request): JsonResponse
    {
        $payload = $request->all();
        Log::channel('mpesa')->info('C2B Validation Received', ['payload' => $payload]);

        $accountReference = $payload['BillRefNumber'] ?? '';

        // Example validation: You could check if this BillRefNumber exists in your students/invoices table.
        // If not, return ResultCode 1 to reject the transaction before the customer loses money.
        // For now, we will auto-accept and push unmatched ones to the Suspense account later.
        
        return response()->json([
            'ResultCode' => 0,
            'ResultDesc' => 'Accepted'
        ]);
    }

    /**
     * Handle C2B Paybill Confirmation (The actual money hitting the account).
     */
    public function c2bConfirmation(Request $request): JsonResponse
    {
        $payload = $request->all();
        Log::channel('mpesa')->info('C2B Confirmation Received', ['payload' => $payload]);

        $transactionId = $payload['TransID'] ?? null;
        $amount        = (string) ($payload['TransAmount'] ?? '0.00');
        $receipt       = $payload['TransID'] ?? null;
        $phone         = $payload['MSISDN'] ?? null;
        $accountRef    = $payload['BillRefNumber'] ?? null;
        $businessShortCode = $payload['BusinessShortCode'] ?? $payload['ShortCode'] ?? null;
        $transTime     = $payload['TransTime'] ?? null; // Format: YYYYMMDDHHMMSS

        try {
            // 1. Immutable Raw Log & Idempotency Check
            $exists = DB::table('mpesa_callbacks')
                ->where('callback_type', 'C2B_CONFIRMATION')
                ->where('mpesa_receipt_number', $receipt)
                ->exists();

            if ($exists) {
                return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Duplicate transaction acknowledged.']);
            }

            $gatewayName = 'mpesa';
            $schoolId = null;
            if ($businessShortCode) {
                $gatewayConfig = DB::table('payment_gateway_configs')
                    ->where('shortcode', $businessShortCode)
                    ->where('is_active', true)
                    ->first();

                if ($gatewayConfig) {
                    $schoolId = $gatewayConfig->school_id;
                    $gatewayName = $gatewayConfig->gateway_name;
                }
            }

            if (!$schoolId) {
                $schoolId = $payload['SchoolID'] ?? null;
            }

            if (!$schoolId) {
                Log::channel('mpesa')->warning('Unable to resolve school for C2B confirmation payload.', ['payload' => $payload]);
            }

            $callbackId = DB::table('mpesa_callbacks')->insertGetId([
                'callback_type'        => 'C2B_CONFIRMATION',
                'gateway_name'         => $gatewayName,
                'mpesa_receipt_number' => $receipt,
                'result_code'          => '0',
                'result_desc'          => 'Success',
                'amount'               => $amount,
                'phone_number'         => $phone,
                'account_reference'    => $accountRef,
                'school_id'            => $schoolId,
                'raw_payload'          => json_encode($payload),
                'created_at'           => now(),
                'updated_at'           => now(),
            ]);

            if ($schoolId) {
                $existingTransaction = DB::table('payment_gateway_transactions')
                    ->where('school_id', $schoolId)
                    ->where('gateway_name', $gatewayName)
                    ->where('gateway_response', $receipt)
                    ->exists();

                if (!$existingTransaction) {
                    DB::table('payment_gateway_transactions')->insert([
                        'school_id' => $schoolId,
                        'student_id' => null,
                        'gateway_name' => $gatewayName,
                        'transaction_type' => 'C2B_CONFIRMATION',
                        'account_reference' => $accountRef,
                        'amount' => $amount,
                        'phone_number' => $phone,
                        'status' => 'successful',
                        'gateway_response' => $receipt,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            // 2. Dispatch processing job to allocate or push to suspense
            ProcessMpesaCallback::dispatchSync(callbackId: $callbackId, type: 'c2b', checkoutRequestId: $accountRef, amount: $amount, receipt: $receipt, phone: $phone);

            return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);

        } catch (\Exception $e) {
            Log::channel('mpesa')->error('C2B Confirmation Processing Failed', ['error' => $e->getMessage()]);
            return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted, but internal error occurred.']);
        }
    }
}