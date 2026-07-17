<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Models\FinanceStatuses;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Student;
use App\Services\Finance\StudentBalanceService;
use Illuminate\Http\JsonResponse;

class StudentFinanceController extends Controller
{
    public function __construct(private readonly StudentBalanceService $balances) {}

    public function summary(Student $student): JsonResponse
    {
        $this->authorize('view', $student);

        $balance = $this->balances->recalculate($student);
        $termBalances = $this->balances->currentTermDueBalances($student);

        return response()->json(['success' => true, 'data' => [
            'student' => $student->load('classRoom'),
            'balance' => $balance,
            'overdue_balance' => (float) $termBalances['overdue'],
            'due_soon_balance' => (float) $termBalances['due_soon'],
        ]]);
    }

    public function statement(Student $student): JsonResponse
    {
        $this->authorize('view', $student);

        $invoices = Invoice::with('items.feeCategory')
            ->where('school_id', currentSchoolId())
            ->where('student_id', $student->id)
            ->orderByDesc('created_at')
            ->get();

        $payments = Payment::with(['receipt', 'allocations.invoice'])
            ->where('school_id', currentSchoolId())
            ->where('student_id', $student->id)
            ->orderByDesc('payment_date')
            ->get();

        $balance = $this->balances->recalculate($student);
        $termBalances = $this->balances->currentTermDueBalances($student);

        return response()->json([
            'success' => true,
            'data' => [
                'student' => $student->load('classRoom'),
                'balance' => $balance,
                'overdue_balance' => (float) $termBalances['overdue'],
                'due_soon_balance' => (float) $termBalances['due_soon'],
                'invoices' => $invoices,
                'payments' => $payments,
            ],
        ]);
    }
}
