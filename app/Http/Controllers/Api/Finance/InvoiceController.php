<?php

// Path: app/Http/Controllers/Api/Finance/InvoiceController.php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Models\FeeStructure;
use App\Models\Invoice;
use App\Models\Student;
use App\Services\Finance\InvoiceGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function __construct(
        protected InvoiceGenerationService $invoiceService
    ) {}

    /**
     * List invoices with optional filtering
     */
    public function index(Request $request): JsonResponse
    {
        $invoices = Invoice::with(['student.classRoom'])
            ->where('school_id', $request->user()->school_id)
            ->when($request->status, fn($q, $status) => $q->where('status', $status))
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->paginate($request->per_page ?? 20);

        return response()->json([
            'success' => true,
            'data'    => $invoices
        ]);
    }

    /**
     * Generate invoices from a fee structure for a student or entire class.
     */
    public function generate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fee_structure_id' => 'required|exists:fee_structures,id',
            'due_date' => 'nullable|date',
            'student_id' => 'nullable|exists:students,id',
        ]);

        $feeStructure = FeeStructure::where('school_id', $request->user()->school_id)
            ->with(['items.feeCategory', 'classRoom'])
            ->findOrFail($validated['fee_structure_id']);

        if (! empty($validated['student_id'])) {
            $student = Student::where('school_id', $request->user()->school_id)
                ->findOrFail($validated['student_id']);

            $invoiceData = [
                'student_id' => $student->id,
                'session_id' => $student->session_id,
                'class_id' => $student->class_id,
                'issue_date' => now()->format('Y-m-d'),
                'due_date' => $validated['due_date'] ?? null,
                'currency' => $feeStructure->currency,
                'items' => $feeStructure->items->map(fn($item) => [
                    'fee_category_id' => $item->fee_category_id,
                    'description' => $item->description ?? $item->feeCategory?->name ?? 'Fee item',
                    'quantity' => 1,
                    'unit_price' => $item->amount,
                    'discount_amount' => '0.00',
                    'revenue_account_id' => $item->revenue_account_id,
                ])->toArray(),
            ];

            $invoice = $this->invoiceService->createInvoice($invoiceData, $request->user()->school_id, $request->user()->id);

            return response()->json([
                'success' => true,
                'message' => 'Invoice generated successfully.',
                'data' => [
                    'created' => 1,
                    'invoice_number' => $invoice->invoice_number,
                ],
            ], 201);
        }

        $studentsQuery = Student::where('school_id', $request->user()->school_id);

        if ($feeStructure->class_id) {
            $studentsQuery->where('class_id', $feeStructure->class_id);
        }

        $students = $studentsQuery->get();

        $created = 0;
        foreach ($students as $student) {
            $invoiceData = [
                'student_id' => $student->id,
                'session_id' => $student->session_id,
                'class_id' => $student->class_id,
                'issue_date' => now()->format('Y-m-d'),
                'due_date' => $validated['due_date'] ?? null,
                'currency' => $feeStructure->currency,
                'items' => $feeStructure->items->map(fn($item) => [
                    'fee_category_id' => $item->fee_category_id,
                    'description' => $item->description ?? $item->feeCategory?->name ?? 'Fee item',
                    'quantity' => 1,
                    'unit_price' => $item->amount,
                    'discount_amount' => '0.00',
                    'revenue_account_id' => $item->revenue_account_id,
                ])->toArray(),
            ];

            $this->invoiceService->createInvoice($invoiceData, $request->user()->school_id, $request->user()->id);
            $created++;
        }

        return response()->json([
            'success' => true,
            'message' => 'Bulk invoices generated successfully.',
            'data' => ['created' => $created],
        ], 201);
    }

    /**
     * Create a manual/custom draft invoice
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'student_id'                 => 'required|exists:students,id',
            'session_id'                 => 'nullable|exists:academic_sessions,id',
            'class_id'                   => 'nullable|exists:class_rooms,id',
            'issue_date'                 => 'nullable|date',
            'due_date'                   => 'nullable|date|after_or_equal:issue_date',
            'waiver_total'               => 'nullable|numeric|min:0',
            'penalty_total'              => 'nullable|numeric|min:0',
            'items'                      => 'required|array|min:1',
            'items.*.fee_category_id'    => 'nullable|exists:fee_categories,id',
            'items.*.description'        => 'required|string|max:255',
            'items.*.quantity'           => 'nullable|numeric|min:0.01',
            'items.*.unit_price'         => 'required|numeric|min:0',
            'items.*.discount_amount'    => 'nullable|numeric|min:0',
            'items.*.revenue_account_id' => 'nullable|exists:chart_of_accounts,id',
        ]);

        $invoice = $this->invoiceService->createInvoice(
            $validated, 
            $request->user()->school_id, 
            $request->user()->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Draft invoice created successfully.',
            'data'    => $invoice
        ], 201);
    }

    /**
     * View detailed invoice and its line items
     */
    public function show(Request $request, Invoice $invoice): JsonResponse
    {
        if ($invoice->school_id !== $request->user()->school_id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'success' => true,
            'data'    => $invoice->load(['student.classRoom', 'invoiceItems.feeCategory'])
        ]);
    }

    /**
     * Lock a draft invoice and issue it to the student
     */
    public function issue(Request $request, Invoice $invoice): JsonResponse
    {
        if ($invoice->school_id !== $request->user()->school_id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        try {
            $issuedInvoice = $this->invoiceService->issueInvoice($invoice);
            
            return response()->json([
                'success' => true,
                'message' => 'Invoice issued successfully.',
                'data'    => $issuedInvoice
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }
}