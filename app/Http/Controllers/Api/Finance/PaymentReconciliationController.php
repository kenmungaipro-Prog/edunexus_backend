<?php

// Route: GET /api/v1/finance/reconciliation
// Route: POST /api/v1/finance/reconciliation/{item}/resolve
// Path: app/Http/Controllers/Api/Finance/PaymentReconciliationController.php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Services\Finance\PaymentAllocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentReconciliationController extends Controller
{
    public function __construct(
        protected PaymentAllocationService $paymentService
    ) {}

    /**
     * List all suspense account items (unmatched M-Pesa payments).
     */
    public function index(Request $request): JsonResponse
    {
        $items = DB::table('payment_reconciliation_items')
            ->where('school_id', $request->user()->school_id)
            ->when($request->status, fn($q, $status) => $q->where('status', $status))
            ->orderByRaw("FIELD(status, 'unmatched', 'resolved', 'refunded')")
            ->orderByDesc('created_at')
            ->paginate($request->per_page ?? 20);

        return response()->json([
            'success' => true,
            'data'    => $items
        ]);
    }

    /**
     * Manually assign an unmatched M-Pesa payment to a specific student.
     */
    public function resolve(Request $request, int $itemId): JsonResponse
    {
        $validated = $request->validate([
            'student_id'       => 'required|exists:students,id',
            'resolution_notes' => 'nullable|string|max:255',
        ]);

        $schoolId = $request->user()->school_id;
        $userId   = $request->user()->id;

        return DB::transaction(function () use ($itemId, $validated, $schoolId, $userId) {
            $item = DB::table('payment_reconciliation_items')
                ->where('id', $itemId)
                ->where('school_id', $schoolId)
                ->lockForUpdate()
                ->first();

            if (!$item) {
                return response()->json(['success' => false, 'message' => 'Item not found.'], 404);
            }

            if ($item->status !== 'unmatched') {
                return response()->json(['success' => false, 'message' => 'This item has already been resolved.'], 422);
            }

            $paymentMethod = 'mpesa';
            if (!empty($item->gateway_name)) {
                $paymentMethod = match ($item->gateway_name) {
                    'coop_bank' => 'bank_transfer',
                    default => 'online',
                };
            }

            // 1. Pass the funds to the Allocation Service to generate the real Payment & Receipt
            $paymentData = [
                'student_id'              => $validated['student_id'],
                'amount'                  => $item->amount,
                'payment_method'          => $paymentMethod,
                'reference_number'        => $item->mpesa_receipt_number,
                'external_transaction_id' => $item->mpesa_receipt_number,
                'payer_phone'             => $item->phone_number,
                'auto_allocate'           => true,
            ];

            $payment = $this->paymentService->collectAndAllocate($paymentData, $schoolId, $userId);

            // 2. Mark the suspense item as resolved
            DB::table('payment_reconciliation_items')
                ->where('id', $itemId)
                ->update([
                    'status'           => 'resolved',
                    'resolved_by'      => $userId,
                    'payment_id'       => $payment->id,
                    'resolved_at'      => now(),
                    'resolution_notes' => $validated['resolution_notes'] ?? 'Manually resolved by Bursar.',
                    'updated_at'       => now(),
                ]);

            return response()->json([
                'success' => true,
                'message' => 'Payment successfully matched and allocated.',
                'data'    => clone $payment
            ]);
        });
    }
}