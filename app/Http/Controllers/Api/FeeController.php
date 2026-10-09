<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FeeRequest;
use App\Models\Fee;
use App\Models\FeeType;
use App\Models\AcademicSession;
use App\Models\FinanceStatuses;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Student;
use App\Models\AuditLog;
use App\Exports\FeesExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class FeeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $fees = Fee::with(['student.classRoom', 'feeType', 'collectedBy'])
            ->whereHas('student', fn ($q) => $q->where('school_id', currentSchoolId()))
            ->when($request->student_id,   fn ($q, $v) => $q->where('student_id', $v))
            ->when($request->fee_type_id,  fn ($q, $v) => $q->where('fee_type_id', $v))
            ->when($request->status,       fn ($q, $v) => $q->where('status', $v))
            ->when($request->method,       fn ($q, $v) => $q->where('payment_method', $v))
            ->when($request->month,        fn ($q, $v) => $q->whereMonth('paid_at', $v))
            ->when($request->year,         fn ($q, $v) => $q->whereYear('paid_at', $v))
            ->orderByDesc('created_at')
            ->paginate($request->per_page ?? 20);

        return response()->json(['success' => true, 'data' => $fees]);
    }

    public function store(FeeRequest $request): JsonResponse
    {
        return $this->collect($request);
    }

    public function collect(Request $request): JsonResponse
    {
        $request->validate([
            'student_id'     => 'required|exists:students,id',
            'fee_type_id'    => 'required|exists:fee_types,id',
            'amount'         => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:' . implode(',', FeeRequest::PAYMENT_METHODS),
            'transaction_id' => ['nullable','string','max:100','required_if:payment_method,mpesa,upi,bank_transfer,bank_deposit,card,online'],
            'remarks'        => 'nullable|string|max:500',
        ]);

        $student = Student::where('school_id', currentSchoolId())->findOrFail($request->student_id);
        $feeType = FeeType::where(function ($query) {
                $query->where('school_id', currentSchoolId())->orWhereNull('school_id');
            })
            ->findOrFail($request->fee_type_id);

        $fee = DB::transaction(function () use ($request, $student, $feeType) {
            $fee = Fee::create([
                'receipt_no'     => $this->generateReceiptNo(),
                'student_id'     => $student->id,
                'fee_type_id'    => $feeType->id,
                'session_id'     => currentSession(),
                'amount'         => $request->amount,
                'payment_method' => $request->payment_method,
                'transaction_id' => $request->transaction_id,
                'collected_by'   => auth()->id(),
                'status'         => 'paid',
                'paid_at'        => now(),
                'remarks'        => $request->remarks,
            ]);

            $this->audit('fee.collected', $fee, null, $fee->toArray());

            return $fee;
        });

        $receiptNo = $fee->receipt_no;

        Cache::forget('dashboard.stats.' . currentSchoolId());

        return response()->json([
            'success'     => true,
            'message'     => 'Payment of ' . formatCurrency($request->amount) . " recorded. Receipt: {$receiptNo}",
            'data'        => $fee->load('student.classRoom', 'feeType', 'collectedBy'),
            'receipt_url' => url("/api/v1/fees/{$fee->id}/receipt"),
        ], 201);
    }

    public function show(Fee $fee): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $fee->load('student.classRoom', 'feeType', 'collectedBy', 'session'),
        ]);
    }

    public function update(Request $request, Fee $fee): JsonResponse
    {
        $request->validate([
            'status'  => 'required|in:paid,pending,overdue,waived,reversed',
            'remarks' => 'nullable|string',
        ]);

        $oldValues = $fee->toArray();
        $fee->update($request->only('status', 'remarks'));
        $this->audit('fee.updated', $fee, $oldValues, $fee->fresh()->toArray());

        return response()->json(['success' => true, 'data' => $fee->fresh()]);
    }

    public function destroy(Fee $fee): JsonResponse
    {
        if ($fee->status === 'reversed') {
            return response()->json(['success' => false, 'message' => 'Fee record is already reversed.'], 422);
        }

        $oldValues = $fee->toArray();

        $fee->update([
            'status' => 'reversed',
            'reversed_at' => now(),
            'reversed_by' => auth()->id(),
            'reversal_reason' => request('reason', 'Reversed from fee management screen.'),
        ]);

        $this->audit('fee.reversed', $fee, $oldValues, $fee->fresh()->toArray());
        Cache::forget('dashboard.stats.' . currentSchoolId());

        return response()->json(['success' => true, 'message' => 'Fee record reversed.']);
    }

    public function receipt(Fee $fee)
    {
        $fee->load('student.classRoom.school', 'feeType', 'collectedBy', 'session');

        $pdf = Pdf::loadView('receipts.fee', compact('fee'))
            ->setPaper('a5', 'portrait');

        return $pdf->download("receipt-{$fee->receipt_no}.pdf");
    }

    public function summary(): JsonResponse
    {
        $schoolId = currentSchoolId();
        $sessionId = currentSession();
        $session = AcademicSession::where('school_id', $schoolId)
            ->whereKey($sessionId)
            ->first();
        $invoiceQuery = Invoice::where('school_id', $schoolId)
            ->where('session_id', $sessionId)
            ->whereNotIn('status', FinanceStatuses::invoiceExcludedFromBalance());
        $totalBudget = (float) (clone $invoiceQuery)->sum('total');
        $appliedToInvoices = (float) (clone $invoiceQuery)->sum('amount_paid');
        $outstanding = (float) (clone $invoiceQuery)
            ->whereIn('status', FinanceStatuses::invoicePayableStatuses())
            ->sum('balance');
        $overdue = (float) (clone $invoiceQuery)
            ->where('status', FinanceStatuses::INVOICE_OVERDUE)
            ->where('balance', '>', 0)
            ->sum('balance');
        $defaulters = (clone $invoiceQuery)
            ->where('status', FinanceStatuses::INVOICE_OVERDUE)
            ->where('balance', '>', 0)
            ->distinct()
            ->count('student_id');

        $paymentQuery = Payment::where('school_id', $schoolId)
            ->whereIn('status', FinanceStatuses::paymentSuccessStatuses());
        if ($session) {
            $paymentQuery->whereBetween('payment_date', [
                $session->start_date->startOfDay(),
                $session->end_date->endOfDay(),
            ]);
        } else {
            $paymentQuery->whereYear('payment_date', now()->year);
        }
        $collected = (float) (clone $paymentQuery)->sum('amount');

        return response()->json([
            'success' => true,
            'data'    => [
                'total_budget'    => $totalBudget,
                'total_collected' => $collected,
                'total_pending'   => $outstanding,
                'overdue_balance' => $overdue,
                'defaulters'      => $defaulters,
                'collection_rate' => $totalBudget > 0 ? round(($appliedToInvoices / $totalBudget) * 100, 1) : 0,
                'session_name'    => $session?->name ?? 'Current calendar year',
                'by_type'         => $this->collectionByType($schoolId, $sessionId),
                'monthly_year'    => now()->year,
                'monthly'         => $this->monthlyCollection($schoolId, now()->year),
            ],
        ]);
    }

    public function defaulters(Request $request): JsonResponse
    {
        $sessionId = currentSession();
        $defaulters = Student::with(['classRoom', 'parent'])
            ->where('school_id', currentSchoolId())
            ->whereHas('invoices', fn ($q) => $q
                ->where('session_id', $sessionId)
                ->where('status', FinanceStatuses::INVOICE_OVERDUE)
                ->where('balance', '>', 0))
            ->withSum(['invoices as overdue_amount' => fn ($q) => $q
                ->where('session_id', $sessionId)
                ->where('status', FinanceStatuses::INVOICE_OVERDUE)
                ->where('balance', '>', 0)], 'balance')
            ->paginate($request->per_page ?? 20);

        return response()->json(['success' => true, 'data' => $defaulters]);
    }

    public function export(Request $request)
    {
        return Excel::download(
            new FeesExport($request->all()),
            'fees-' . now()->format('Y-m-d') . '.xlsx'
        );
    }

    private function generateReceiptNo(): string
    {
        $schoolId = currentSchoolId() ?? 0;

        do {
            $receiptNo = 'RCP-' . $schoolId . '-' . now()->format('YmdHis') . '-' . random_int(100, 999);
        } while (Fee::where('receipt_no', $receiptNo)->exists());

        return $receiptNo;
    }

    private function audit(string $action, Fee $fee, ?array $oldValues, ?array $newValues): void
    {
        AuditLog::create([
            'school_id' => currentSchoolId(),
            'user_id' => auth()->id(),
            'action' => $action,
            'auditable_type' => Fee::class,
            'auditable_id' => $fee->id,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    private function collectionByType(int $schoolId, int $sessionId): array
    {
        $categories = DB::table('invoice_items as items')
            ->join('invoices', 'invoices.id', '=', 'items.invoice_id')
            ->leftJoin('fee_categories', 'fee_categories.id', '=', 'items.fee_category_id')
            ->where('invoices.school_id', $schoolId)
            ->where('invoices.session_id', $sessionId)
            ->whereNotIn('invoices.status', FinanceStatuses::invoiceExcludedFromBalance())
            ->groupByRaw("COALESCE(fee_categories.name, items.description, 'Uncategorized')")
            ->selectRaw("COALESCE(fee_categories.name, items.description, 'Uncategorized') as type")
            ->selectRaw('SUM(items.total) as amount')
            ->selectRaw('SUM(CASE WHEN invoices.total > 0 THEN items.total * LEAST(GREATEST(invoices.amount_paid, 0), invoices.total) / invoices.total ELSE 0 END) as collected')
            ->get();

        return $categories->map(fn ($category) => [
            'type'      => $category->type,
            'amount'    => (float) $category->amount,
            'collected' => (float) $category->collected,
            'rate'      => (float) $category->amount > 0
                ? round(((float) $category->collected / (float) $category->amount) * 100, 1)
                : 0,
        ])->all();
    }

    private function monthlyCollection(int $schoolId, int $year): array
    {
        $monthlyAmounts = Payment::where('school_id', $schoolId)
            ->whereIn('status', FinanceStatuses::paymentSuccessStatuses())
            ->whereYear('payment_date', $year)
            ->selectRaw('MONTH(payment_date) as month_number, SUM(amount) as collected')
            ->groupByRaw('MONTH(payment_date)')
            ->pluck('collected', 'month_number');

        return collect(range(1, 12))->map(fn ($month) => [
            'month'     => now()->setDate($year, $month, 1)->format('M'),
            'collected' => (float) ($monthlyAmounts[$month] ?? 0),
        ])->all();
    }
}
