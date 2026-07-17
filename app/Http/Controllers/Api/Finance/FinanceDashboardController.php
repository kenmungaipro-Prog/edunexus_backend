<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Models\FinanceStatuses;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\StudentFinanceBalance;
use Illuminate\Http\JsonResponse;

class FinanceDashboardController extends Controller
{
    public function summary(): JsonResponse
    {
        $schoolId = currentSchoolId();

        $invoiced = Invoice::where('school_id', $schoolId)
            ->whereNotIn('status', FinanceStatuses::invoiceExcludedFromBalance())
            ->sum('total');

        $collected = Payment::where('school_id', $schoolId)
            ->whereIn('status', FinanceStatuses::paymentSuccessStatuses())
            ->sum('amount');

        $outstanding = StudentFinanceBalance::where('school_id', $schoolId)->sum('balance');

        $today = Payment::where('school_id', $schoolId)
            ->whereDate('payment_date', today())
            ->whereIn('status', FinanceStatuses::paymentSuccessStatuses())
            ->sum('amount');

        $overdue = Invoice::where('school_id', $schoolId)
            ->where('status', FinanceStatuses::INVOICE_OVERDUE)
            ->sum('balance');

        $dueSoon = Invoice::where('school_id', $schoolId)
            ->whereIn('status', [FinanceStatuses::INVOICE_ISSUED, FinanceStatuses::INVOICE_PARTIALLY_PAID])
            ->whereBetween('due_date', [today(), today()->addDays(7)])
            ->sum('balance');

        return response()->json([
            'success' => true,
            'data' => [
                'total_invoiced' => (float) $invoiced,
                'total_collected' => (float) $collected,
                'outstanding_balance' => (float) $outstanding,
                'today_collections' => (float) $today,
                'overdue_balance' => (float) $overdue,
                'due_soon_balance' => (float) $dueSoon,
                'collection_rate' => (float) $invoiced > 0 ? round(((float) $collected / (float) $invoiced) * 100, 1) : 0,
            ],
        ]);
    }

    public function recentPayments(): JsonResponse
    {
        $payments = Payment::with(['student.classRoom', 'receipt'])
            ->where('school_id', currentSchoolId())
            ->orderByDesc('payment_date')
            ->limit(10)
            ->get();

        return response()->json(['success' => true, 'data' => $payments]);
    }
}
