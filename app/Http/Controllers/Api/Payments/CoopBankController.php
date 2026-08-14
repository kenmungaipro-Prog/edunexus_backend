<?php

namespace App\Http\Controllers\Api\Payments;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Jobs\ProcessPaymentGatewayCallback;
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
            ProcessPaymentGatewayCallback::dispatchSync(callbackId: $callbackId, type: 'c2b', checkoutRequestId: $parsedAccountRef ?? $account, amount: $amount, receipt: $receipt, phone: $phone);

            return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);

        } catch (\Exception $e) {
            Log::channel('mpesa')->error('Coop Confirmation Processing Failed', ['error' => $e->getMessage()]);
            return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted, but internal error occurred.']);
        }
    }

    /**
     * Handle Co-op Bank INS (Instant Notification Service) payloads.
     * Normalizes the payload and inserts a generic callback and transaction record,
     * then dispatches the existing processing job (reusing C2B flow).
     * Responds with the bank's expected acknowledgement JSON.
     */
    public function instantNotification(Request $request): JsonResponse
    {
        $payload = $request->all();
        Log::info('INS endpoint reached using default logger');
        Log::channel('mpesa')->info('Coop INS Received', ['payload' => $payload, 'headers' => $request->headers->all()]);

        // Attempt to extract core fields from INS payload
        $messageReference = $payload['MessageReference'] ?? null;
        $messageDateTime = $payload['MessageDateTime'] ?? now()->toISOString();
        $transactionId = $payload['TransactionId'] ?? $payload['TransactionID'] ?? null;
        $paymentRef = $payload['PaymentRef'] ?? $payload['PaymentRefNo'] ?? null;
        $amount = (string) ($payload['Amount'] ?? $payload['TransAmount'] ?? '0.00');
        $accountNumber = $payload['AccountNumber'] ?? null;
        $eventType = $payload['EventType'] ?? null;
        $narration = $payload['Narration'] ?? null;
        $transactionDate = $payload['TransactionDate'] ?? null;
        $insPhoneNumber = trim((string) ($payload['MSISDN'] ?? $payload['Phone'] ?? $payload['msisdn'] ?? ''));
        $insPhoneNumber = $insPhoneNumber !== '' ? $insPhoneNumber : 'UNKNOWN';

        // CustMemoLine1 is the likely holder for payer reference (e.g. admission no)
        $custLine = data_get($payload, 'CustMemo.CustMemoLine1');
        $parsedAccountRef = null;
        $processingNotes = [];

        if ($custLine) {
            $processingNotes[] = 'custmemo: ' . $custLine;
            // Common formats: "728210595 ABD01" or "400222#ADM001" or "ADM001"
            if (str_contains($custLine, '#')) {
                [$prefix, $ref] = explode('#', $custLine, 2) + [null, null];
                $parsedAccountRef = $ref ?: trim($custLine);
                $processingNotes[] = 'parsed_by_hash_prefix: ' . ($prefix ?? '');
            } else {
                // If space-separated, take the last token as probable reference
                $parts = preg_split('/\s+/', trim($custLine));
                if (count($parts) > 1) {
                    $parsedAccountRef = end($parts);
                    $processingNotes[] = 'parsed_last_token: ' . $parsedAccountRef;
                } else {
                    $parsedAccountRef = trim($custLine);
                }
            }
        }

        // Resolve gateway and school (best-effort)
        $gatewayName = 'coop_400222';
        $schoolId = null;
        try {
            $gatewayConfig = DB::table('payment_gateway_configs')
                ->where('gateway_name', $gatewayName)
                ->where('is_active', true)
                ->first();

            if ($gatewayConfig) {
                $schoolId = $gatewayConfig->school_id;
            }
        } catch (\Exception $e) {
            // If the table/columns aren't present or query fails, log and continue
            Log::channel('mpesa')->warning('Coop INS gateway config lookup failed', ['error' => $e->getMessage()]);
        }

        // Try to resolve a student if we have a parsed reference and a school
        $resolvedStudentId = null;
        if (!empty($parsedAccountRef) && $schoolId) {
            $student = Student::where('school_id', $schoolId)
                ->where('admission_no', $parsedAccountRef)
                ->first();
            $resolvedStudentId = $student?->id;
            if ($resolvedStudentId) {
                $processingNotes[] = 'matched_student_id: ' . $resolvedStudentId;
            }
        }

        try {
            // Idempotency: avoid double-processing the same INS receipt
            $insReceipt = $paymentRef ?? $transactionId;
            $exists = false;
            if (!empty($insReceipt)) {
                $exists = DB::table('mpesa_callbacks')
                    ->where('callback_type', 'INS')
                    ->where('mpesa_receipt_number', $insReceipt)
                    ->exists();
            }

            if ($exists) {
                Log::channel('mpesa')->warning('Duplicate Coop INS Detected', [
                    'receipt' => $insReceipt,
                    'payload' => $payload,
                    'callback_type' => 'INS',
                ]);

                // Acknowledge duplicate without re-processing
                $ack = [
                    'MessageReference' => $messageReference ?? $insReceipt,
                    'MessageDateTime'  => $messageDateTime,
                    'MessageCode'      => '0',
                    'MessageDescription' => 'Acknowledged',
                ];

                return response()->json($ack);
            }

            // Persist raw callback into the generic callbacks table
            $callbackId = DB::table('mpesa_callbacks')->insertGetId([
                'callback_type'        => 'INS',
                'gateway_name'         => $gatewayName,
                'mpesa_receipt_number' => $insReceipt,
                'result_code'          => '0',
                'result_desc'          => 'INS Received',
                'amount'               => $amount,
                'phone_number'         => $insPhoneNumber,
                'account_reference'    => $parsedAccountRef ?? $accountNumber,
                'school_id'            => $schoolId,
                'raw_payload'          => json_encode($payload),
                'is_processed'         => false,
                'processing_notes'     => implode('; ', $processingNotes) ?: null,
                'created_at'           => now(),
                'updated_at'           => now(),
            ]);

            // Create a normalized gateway transaction only when we have a resolved school,
            // a non-empty receipt, and a positive amount.
            if ($schoolId && !empty($insReceipt) && floatval($amount) > 0) {
                $existingTransaction = DB::table('payment_gateway_transactions')
                    ->where('school_id', $schoolId)
                    ->where('gateway_name', $gatewayName)
                    ->where('gateway_response', $insReceipt)
                    ->exists();

                if (!$existingTransaction) {
                    DB::table('payment_gateway_transactions')->insert([
                        'school_id' => $schoolId,
                        'student_id' => $resolvedStudentId,
                        'gateway_name' => $gatewayName,
                        'transaction_type' => 'INS',
                        'account_reference' => $parsedAccountRef ?? $accountNumber,
                        'amount' => $amount,
                        'phone_number' => $insPhoneNumber,
                        'status' => 'successful',
                        'gateway_response' => $insReceipt,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            // Dispatch using the existing processing job (treat as C2B confirmation flow)
            ProcessPaymentGatewayCallback::dispatchSync(callbackId: $callbackId, type: 'c2b', checkoutRequestId: $parsedAccountRef ?? $accountNumber, amount: $amount, receipt: $insReceipt, phone: $insPhoneNumber);

            // Acknowledge using Co-op INS expected format
            $ack = [
                'MessageReference' => $messageReference ?? $insReceipt,
                'MessageDateTime'  => $messageDateTime,
                'MessageCode'      => '0',
                'MessageDescription' => 'Acknowledged',
            ];

            return response()->json($ack);

        } catch (\Exception $e) {
            Log::channel('mpesa')->error('Coop INS Processing Failed', ['error' => $e->getMessage()]);
            // Still reply with acknowledgement to avoid retries, but log the issue
            $ack = [
                'MessageReference' => $messageReference ?? ($paymentRef ?? $transactionId),
                'MessageDateTime'  => $messageDateTime,
                'MessageCode'      => '0',
                'MessageDescription' => 'Acknowledged',
            ];
            return response()->json($ack);
        }
    }
}
