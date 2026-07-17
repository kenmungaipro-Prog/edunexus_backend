<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Models\FinanceStatuses;
use App\Models\Payment;
use App\Services\Finance\PaymentAllocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function __construct(protected PaymentAllocationService $paymentService) {}

    public function index(Request $request): JsonResponse
    {
        $payments = Payment::with(['student.classRoom', 'receipt'])
            ->where('school_id', currentSchoolId())
            ->when($request->student_id, fn($q) => $q->where('student_id', $request->student_id))
            ->when($request->status, fn($q, $status) => $q->where('status', $status))
            ->when($request->search, function ($query, $search) {
                $query->where(function ($sub) use ($search) {
                    $sub->where('payment_number', 'like', "%{$search}%")
                        ->orWhere('reference_number', 'like', "%{$search}%")
                        ->orWhere('external_transaction_id', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->paginate($request->per_page ?? 20);

        return response()->json(['success' => true, 'data' => $payments]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'student_id' => [
                'required',
                Rule::exists('students', 'id')->where(fn ($query) => $query->where('school_id', currentSchoolId())),
            ],
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'nullable|string',
            'payment_channel' => 'nullable|string',
            'reference_number' => 'nullable|string|max:255',
            'external_transaction_id' => 'nullable|string|max:255',
            'payer_name' => 'nullable|string|max:255',
            'payer_phone' => 'nullable|string|max:30',
            'auto_allocate' => 'sometimes|boolean',
        ]);

        $payment = $this->paymentService->collectAndAllocate([
            'student_id' => $validated['student_id'],
            'amount' => (string) $validated['amount'],
            'payment_method' => $validated['payment_method'] ?? 'cash',
            'payment_channel' => $validated['payment_channel'] ?? null,
            'reference_number' => $validated['reference_number'] ?? null,
            'external_transaction_id' => $validated['external_transaction_id'] ?? null,
            'payer_name' => $validated['payer_name'] ?? null,
            'payer_phone' => $validated['payer_phone'] ?? null,
            'auto_allocate' => $validated['auto_allocate'] ?? true,
        ], currentSchoolId(), $request->user()->id);

        return response()->json([
            'success' => true,
            'data' => $payment->load(['student.classRoom', 'allocations.invoice', 'receipt']),
        ], 201);
    }

    public function show(Request $request, Payment $payment): JsonResponse
    {
        abort_if($payment->school_id !== currentSchoolId(), 404);

        return response()->json([
            'success' => true,
            'data' => $payment->load(['student.classRoom', 'allocations.invoice', 'receipt']),
        ]);
    }

    public function collect(Request $request): JsonResponse
    {
        return $this->store($request);
    }

    public function mpesaStatus(Request $request): JsonResponse
    {
        $transactions = DB::table('payment_gateway_transactions as pgt')
            ->leftJoin('mpesa_callbacks as mc', function ($join) {
                $join->on('mc.merchant_request_id', '=', 'pgt.merchant_request_id')
                    ->orOn('mc.checkout_request_id', '=', 'pgt.checkout_request_id');
            })
            ->where('pgt.school_id', currentSchoolId())
            ->select(
                'pgt.id as gateway_transaction_id',
                'pgt.gateway_name',
                'pgt.transaction_type',
                'pgt.merchant_request_id',
                'pgt.checkout_request_id',
                'pgt.account_reference',
                'pgt.amount as gateway_amount',
                'pgt.phone_number as gateway_phone',
                'pgt.status',
                'pgt.gateway_response',
                'pgt.created_at as gateway_created_at',
                'pgt.updated_at as gateway_updated_at',
                'mc.id as callback_id',
                'mc.callback_type',
                'mc.mpesa_receipt_number',
                'mc.result_code as callback_result_code',
                'mc.result_desc as callback_result_desc',
                'mc.amount as callback_amount',
                'mc.phone_number as callback_phone',
                'mc.is_processed as callback_processed',
                'mc.processing_notes',
                'mc.created_at as callback_created_at',
                'mc.updated_at as callback_updated_at'
            )
            ->orderByDesc('pgt.created_at')
            ->orderByDesc('pgt.id')
            ->limit(50)
            ->get();

        $data = $transactions->map(function ($item) {
            $status = $item->status;
            if ($item->callback_result_code === '0' && $item->status !== 'successful') {
                $status = 'successful';
            } elseif ($item->callback_result_code && $item->callback_result_code !== '0' && $item->status !== 'failed') {
                $status = 'failed';
            }

            return [
                'gateway_transaction_id' => $item->gateway_transaction_id,
                'gateway_name' => $item->gateway_name,
                'transaction_type' => $item->transaction_type,
                'merchant_request_id' => $item->merchant_request_id,
                'checkout_request_id' => $item->checkout_request_id,
                'account_reference' => $item->account_reference,
                'gateway_amount' => (float) $item->gateway_amount,
                'gateway_phone' => $item->gateway_phone,
                'status' => $status,
                'gateway_response' => $item->gateway_response,
                'gateway_created_at' => $item->gateway_created_at,
                'gateway_updated_at' => $item->gateway_updated_at,
                'callback_id' => $item->callback_id,
                'callback_type' => $item->callback_type,
                'callback_gateway_name' => $item->gateway_name,
                'mpesa_receipt_number' => $item->mpesa_receipt_number,
                'callback_result_code' => $item->callback_result_code,
                'callback_result_desc' => $item->callback_result_desc,
                'callback_amount' => (float) $item->callback_amount,
                'callback_phone' => $item->callback_phone,
                'callback_processed' => (bool) $item->callback_processed,
                'processing_notes' => $item->processing_notes,
                'callback_created_at' => $item->callback_created_at,
                'callback_updated_at' => $item->callback_updated_at,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function allocate(Request $request, Payment $payment): JsonResponse
    {
        abort_if($payment->school_id !== currentSchoolId(), 404);

        if ($payment->status === FinanceStatuses::PAYMENT_FULLY_ALLOCATED) {
            return response()->json(['success' => false, 'message' => 'Payment is already fully allocated.'], 422);
        }

        if (in_array($payment->status, [FinanceStatuses::PAYMENT_FAILED, FinanceStatuses::PAYMENT_REVERSED, FinanceStatuses::PAYMENT_REFUNDED])) {
            return response()->json(['success' => false, 'message' => 'Cannot allocate a failed, reversed, or refunded payment.'], 422);
        }

        $this->paymentService->allocate($payment->fresh());

        return response()->json([
            'success' => true,
            'data' => $payment->fresh()->load(['allocations.invoice', 'receipt']),
        ]);
    }

    public function refund(Request $request, Payment $payment): JsonResponse
    {
        abort_if($payment->school_id !== currentSchoolId(), 404);

        if (in_array($payment->status, [FinanceStatuses::PAYMENT_FAILED, FinanceStatuses::PAYMENT_REVERSED, FinanceStatuses::PAYMENT_REFUNDED])) {
            return response()->json(['success' => false, 'message' => 'Cannot refund this payment.'], 422);
        }

        $refreshedPayment = $payment->fresh();
        $amount = $this->paymentService->refundUnallocatedPayment($refreshedPayment);

        if ($amount <= 0) {
            return response()->json(['success' => false, 'message' => 'No unallocated amount available for refund.'], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Unallocated overpayment refunded successfully.',
            'data' => [
                'payment_id' => $refreshedPayment->id,
                'amount_refunded' => number_format($amount, 2, '.', ''),
                'status' => $refreshedPayment->status,
            ],
        ]);
    }

    public function allocationResults(Request $request, Payment $payment): JsonResponse
    {
        abort_if($payment->school_id !== currentSchoolId(), 404);

        return response()->json([
            'success' => true,
            'data' => $payment->allocations()->with('invoice')->get(),
        ]);
    }

    public function reverse(Request $request, Payment $payment): JsonResponse
    {
        abort_if($payment->school_id !== currentSchoolId(), 404);

        if ($payment->status === FinanceStatuses::PAYMENT_REVERSED) {
            return response()->json(['success' => false, 'message' => 'Payment already reversed.'], 422);
        }

        if ($payment->status === FinanceStatuses::PAYMENT_FAILED) {
            return response()->json(['success' => false, 'message' => 'Cannot reverse a failed payment.'], 422);
        }

        return DB::transaction(function () use ($payment) {
            $payment->load('allocations.invoice');

            foreach ($payment->allocations as $allocation) {
                $invoice = $allocation->invoice;
                if (! $invoice) {
                    continue;
                }

                $invoice->amount_paid = max(0, (float) $invoice->amount_paid - (float) $allocation->amount_allocated);
                $invoice->balance = max(0, (float) $invoice->balance + (float) $allocation->amount_allocated);
                $invoice->status = $invoice->balance <= 0
                    ? FinanceStatuses::INVOICE_PAID
                    : FinanceStatuses::INVOICE_PARTIALLY_PAID;
                $invoice->save();
            }

            $payment->status = FinanceStatuses::PAYMENT_REVERSED;
            $payment->save();

            return response()->json([
                'success' => true,
                'message' => 'Payment reversed successfully.',
                'data' => $payment->fresh()->load(['allocations.invoice', 'receipt']),
            ]);
        });
    }

    public function receipt(Request $request, Payment $payment): JsonResponse
    {
        abort_if($payment->school_id !== currentSchoolId(), 404);

        $receipt = $payment->receipt;
        if (! $receipt) {
            return response()->json(['success' => false, 'message' => 'Receipt not found for payment.'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $receipt->load(['payment.student.classRoom']),
        ]);
    }
}
