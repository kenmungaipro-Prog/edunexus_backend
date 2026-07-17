<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FeeRequest;
use App\Models\Fee;
use App\Models\FeeType;
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
            'transaction_id' => [
                'nullable',
                'string',
                'max:100',
                function (string $attribute, mixed $value, \Closure $fail) use ($request) {
                    $requiresReference = in_array($request->payment_method, [
                        'mpesa',
                        'bank_transfer',
                        'bank_deposit',
                        'card',
                        'online',
                    ], true);

                    if ($requiresReference && blank($value)) {
                        $fail('Transaction reference is required for M-Pesa, bank, card, and online payments.');
                    }
                },
            ],
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

        $totalStudents = Student::where('school_id', $schoolId)->where('status', 'active')->count();
        $feeTypes      = FeeType::where('school_id', $schoolId)->get();
        $totalBudget   = $feeTypes->sum('amount') * $totalStudents;

        $collected = Fee::whereHas('student', fn ($q) => $q->where('school_id', $schoolId))
            ->where('session_id', $sessionId)
            ->where('status', 'paid')
            ->sum('amount');

        $pending = Fee::whereHas('student', fn ($q) => $q->where('school_id', $schoolId))
            ->where('session_id', $sessionId)
            ->where('status', 'pending')
            ->sum('amount');

        $defaulters = Fee::whereHas('student', fn ($q) => $q->where('school_id', $schoolId))
            ->where('status', 'overdue')
            ->distinct('student_id')
            ->count('student_id');

        return response()->json([
            'success' => true,
            'data'    => [
                'total_budget'   => $totalBudget,
                'total_collected'=> (float) $collected,
                'total_pending'  => (float) $pending,
                'defaulters'     => $defaulters,
                'collection_rate'=> $totalBudget > 0 ? round(($collected / $totalBudget) * 100, 1) : 0,
                'by_type'        => $this->collectionByType($schoolId, $sessionId),
                'monthly'        => $this->monthlyCollection($schoolId),
            ],
        ]);
    }

    public function defaulters(Request $request): JsonResponse
    {
        $defaulters = Student::with('classRoom')
            ->where('school_id', currentSchoolId())
            ->whereHas('fees', fn ($q) => $q->where('status', 'overdue'))
            ->withSum(['fees as overdue_amount' => fn ($q) => $q->where('status', 'overdue')], 'amount')
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
        return FeeType::where('school_id', $schoolId)
            ->withSum(['fees as collected' => fn ($q) => $q->where('status', 'paid')->where('session_id', $sessionId)], 'amount')
            ->get()
            ->map(fn ($ft) => [
                'type'      => $ft->name,
                'amount'    => $ft->amount,
                'collected' => (float) ($ft->collected ?? 0),
                'rate'      => $ft->amount > 0 ? round(($ft->collected / $ft->amount) * 100, 1) : 0,
            ])
            ->toArray();
    }

    private function monthlyCollection(int $schoolId): array
    {
        return collect(range(1, 12))->map(fn ($m) => [
            'month'     => now()->month($m)->format('M'),
            'collected' => (float) Fee::whereHas('student', fn ($q) => $q->where('school_id', $schoolId))
                ->whereMonth('paid_at', $m)
                ->where('status', 'paid')
                ->sum('amount'),
        ])->toArray();
    }
}


