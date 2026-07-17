<?php

namespace App\Http\Controllers\Api\Payments;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Jobs\ProcessMpesaCallback;
use App\Models\Student;

class CoopBankController extends Controller
{
    /**
     * Handle Co-op Bank C2B Validation (optional).
     */
    public function validation(Request $request): JsonResponse
    {
        $payload = $request->all();
        Log::channel('mpesa')->info('Coop Validation Received', ['payload' => $payload]);

        // Auto-accept. We'll push unmatched ones to suspense for manual resolution.
        return response()->json([
            'ResultCode' => 0,
            'ResultDesc' => 'Accepted'
        ]);
    }

    /**
     * Handle Co-op Bank C2B Confirmation (money hitting the account).
     * Persists raw payload into mpesa_callbacks with gateway_name = 'coop_400222'.
     */
    public function confirmation(Request $request): JsonResponse
    {
        $payload = $request->all();
        Log::channel('mpesa')->info('Coop Confirmation Received', ['payload' => $payload]);

        // Common mapping attempts
        $receipt = $payload['TransID'] ?? $payload['Receipt'] ?? $payload['TransactionID'] ?? $payload['TransID'] ?? null;
        $amount  = (string) ($payload['TransAmount'] ?? $payload['Amount'] ?? $payload['amount'] ?? '0.00');
        $phone   = $payload['MSISDN'] ?? $payload['Phone'] ?? $payload['msisdn'] ?? null;
        $account = $payload['AccountNumber'] ?? $payload['AccountRef'] ?? $payload['BillRefNumber'] ?? $payload['Account'] ?? null;
        $businessShortCode = $payload['BusinessShortCode'] ?? $payload['BusinessCode'] ?? $payload['ShortCode'] ?? '400222';
        $transTime = $payload['TransTime'] ?? null;

        try {
            // Idempotency: avoid double-processing the same receipt
            if (empty($receipt)) {
                Log::channel('mpesa')->warning('Coop Confirmation Received without receipt', [
                    'payload' => $payload,
                    'callback_type' => 'C2B_CONFIRMATION',
                ]);
            }

            $exists = false;
            if (!empty($receipt)) {
                $exists = DB::table('mpesa_callbacks')
                    ->where('callback_type', 'C2B_CONFIRMATION')
                    ->where('mpesa_receipt_number', $receipt)
                    ->exists();
            }

            if ($exists) {
                Log::channel('mpesa')->warning('Duplicate Coop Confirmation Detected', [
                    'receipt' => $receipt,
                    'payload' => $payload,
                    'callback_type' => 'C2B_CONFIRMATION',
                ]);

                return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Duplicate transaction acknowledged.']);
            }

            // Resolve gateway/school by shortcode
            $gatewayName = 'coop_400222';
            $schoolId = null;
            $gatewayConfig = DB::table('payment_gateway_configs')
                ->where('shortcode', $businessShortCode)
                ->where('is_active', true)
                ->first();

            if ($gatewayConfig) {
                $schoolId = $gatewayConfig->school_id;
                $gatewayName = $gatewayConfig->gateway_name ?: $gatewayName;
            } else {
                // fallback: try lookup by gateway_name
                $fallback = DB::table('payment_gateway_configs')
                    ->where('gateway_name', 'coop_400222')
                    ->where('is_active', true)
                    ->first();
                if ($fallback) {
                    $schoolId = $fallback->school_id;
                }
            }

            Log::channel('mpesa')->info('Coop Confirmation Resolved', [
                'receipt' => $receipt,
                'gateway_name' => $gatewayName,
                'school_id' => $schoolId,
                'business_shortcode' => $businessShortCode,
                'account_reference' => $account,
            ]);

            // Parse account format: [BusinessCode]#[StudentAdmNo]
            $parsedAccountRef = null;
            $processingNotes = [];
            if ($account) {
                $processingNotes[] = 'original_account: ' . $account;
                if (str_contains($account, '#')) {
                    [$biz, $adm] = explode('#', $account, 2) + [null, null];
                    $parsedAccountRef = $adm ?: $account;
                    $processingNotes[] = 'parsed_business_code: ' . ($biz ?: '');
                    $processingNotes[] = 'parsed_student_adm: ' . ($adm ?: '');
                } else {
                    $parsedAccountRef = $account;
                }
            }

            $resolvedStudentId = null;
            if (!empty($parsedAccountRef) && $schoolId) {
                $student = Student::where('school_id', $schoolId)
                    ->where('admission_no', $parsedAccountRef)
                    ->first();
                $resolvedStudentId = $student?->id;
            }

            Log::channel('mpesa')->info('Coop Confirmation Resolved', [
                'receipt' => $receipt,
                'gateway_name' => $gatewayName,
                'school_id' => $schoolId,
                'business_shortcode' => $businessShortCode,
                'account_reference' => $account,
                'parsed_account_reference' => $parsedAccountRef,
                'student_id' => $resolvedStudentId,
            ]);

            // Persist raw callback
            $callbackId = DB::table('mpesa_callbacks')->insertGetId([
                'callback_type'        => 'C2B_CONFIRMATION',
                'gateway_name'         => $gatewayName,
                'mpesa_receipt_number' => $receipt,
                'result_code'          => '0',
                'result_desc'          => 'Success',
                'amount'               => $amount,
                'phone_number'         => $phone,
                'account_reference'    => $parsedAccountRef ?? $account,
                'school_id'            => $schoolId,
                'raw_payload'          => json_encode($payload),
                'is_processed'         => false,
                'processing_notes'     => implode("; ", $processingNotes) ?: null,
                'created_at'           => now(),
                'updated_at'           => now(),
            ]);

            // Create a normalized transaction record if the school is known
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
                        'account_reference' => $parsedAccountRef ?? $account,
                        'amount' => $amount,
                        'phone_number' => $phone,
                        'status' => 'successful',
                        'gateway_response' => $receipt,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            // Dispatch processing job to allocate payment or move to suspense
            ProcessMpesaCallback::dispatchSync(callbackId: $callbackId, type: 'c2b', checkoutRequestId: $parsedAccountRef ?? $account, amount: $amount, receipt: $receipt, phone: $phone);

            return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);

        } catch (\Exception $e) {
            Log::channel('mpesa')->error('Coop Confirmation Processing Failed', ['error' => $e->getMessage()]);
            return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted, but internal error occurred.']);
        }
    }
}
