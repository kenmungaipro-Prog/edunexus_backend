<?php

// Route: GET /api/v1/students/{student}/statement
// Path: app/Http/Controllers/Api/Finance/StudentStatementController.php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\StudentFinanceBalance;
use App\Services\Finance\StudentBalanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentStatementController extends Controller
{
    /**
     * Retrieve the comprehensive financial statement for a specific student.
     */
    public function show(Request $request, Student $student): JsonResponse
    {
        $schoolId = $request->user()->school_id;

        // Security check
        if ($student->school_id !== $schoolId) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $student->load('classRoom');

        // 1. Recalculate the denormalized fast-read balance so overpayments and credits are reflected.
        $balanceService = app(StudentBalanceService::class);
        $balance = $balanceService->recalculate($student);

        // 2. Calculate dynamic timeline metrics based on the current term period.
        $termBalances = $balanceService->currentTermDueBalances($student);

        // 3. Fetch full historical ledgers
        $invoices = Invoice::where('student_id', $student->id)
            ->orderBy('issue_date', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        $payments = Payment::with('receipt')
            ->where('student_id', $student->id)
            ->orderBy('payment_date', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'student'          => $student,
                'balance'          => $balance,
                'overdue_balance'  => (float) $termBalances['overdue'],
                'due_soon_balance' => (float) $termBalances['due_soon'],
                'invoices'         => $invoices,
                'payments'         => $payments,
            ]
        ]);
    }
}