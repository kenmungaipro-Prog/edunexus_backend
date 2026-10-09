<?php

// Route: GET /api/v1/finance/receipts
// Route: GET /api/v1/finance/receipts/{receipt}
// Path: app/Http/Controllers/Api/Finance/ReceiptController.php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Models\Receipt;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReceiptController extends Controller
{
    /**
     * List paginated receipts with student and class relationships.
     */
    public function index(Request $request): JsonResponse
    {
        $receipts = Receipt::with(['payment.student.classRoom'])
            ->where('school_id', $request->user()->school_id)
            ->when($request->status, fn($q, $status) => $q->where('status', $status))
            ->orderByDesc('receipt_date')
            ->orderByDesc('id')
            ->paginate($request->per_page ?? 20);

        return response()->json([
            'success' => true,
            'data'    => $receipts
        ]);
    }

    /**
     * Show a single receipt with full allocation history for printing/viewing.
     */
    public function show(Request $request, Receipt $receipt): JsonResponse
    {
        if ($receipt->school_id !== $request->user()->school_id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'success' => true,
            'data'    => $receipt->load([
                'payment.student.classRoom', 
                'payment.allocations.invoice'
            ])
        ]);
    }

    public function pdf(Request $request, Receipt $receipt): Response
    {
        if ($receipt->school_id !== $request->user()->school_id) {
            abort(404);
        }

        if ($receipt->status !== 'issued') {
            abort(422, 'Only issued receipts can be printed.');
        }

        $receipt->load([
            'school',
            'payment.student.classRoom',
            'payment.student.school',
            'payment.receivedBy',
            'payment.allocations.invoice',
        ]);

        $pdf = Pdf::loadView('receipts.payment', compact('receipt'))
            ->setPaper('a5', 'portrait');

        return $pdf->download("receipt-{$receipt->receipt_number}.pdf");
    }
}