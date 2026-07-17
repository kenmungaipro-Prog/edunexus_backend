<?php

// Route: GET /api/v1/portal/students/{student}/finance
// Route: POST /api/v1/portal/payments/mpesa/stk-push
// Path: app/Http/Controllers/Api/Payments/ParentPaymentController.php

namespace App\Http\Controllers\Api\Payments;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\StudentFinanceBalance;
use App\Services\Payments\MpesaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Exception;

class ParentPaymentController extends Controller
{
    public function __construct(
        protected MpesaService $mpesaService
    ) {}

    /**
     * Get the financial summary for a parent's child.
     */
    public function studentFinanceSummary(Request $request, Student $student): JsonResponse
    {
        $this->authorize('view', $student);

        $balance = StudentFinanceBalance::where('student_id', $student->id)
            ->where('school_id', $student->school_id)
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'student_name' => $student->full_name,
                'admission_no' => $student->admission_no,
                'balance'      => $balance ? $balance->balance : '0.00',
            ]
        ]);
    }

    /**
     * Parent analytics summary for the portal.
     */
    public function analytics(): JsonResponse
    {
        $user = auth()->user();
        $children = Student::with('classRoom')
            ->where('parent_id', $user->id)
            ->where('school_id', $user->school_id)
            ->get();

        $balanceMap = StudentFinanceBalance::whereIn('student_id', $children->pluck('id'))
            ->pluck('balance', 'student_id')
            ->all();

        $payload = $children->map(function (Student $child) use ($balanceMap) {
            return [
                'id'           => $child->id,
                'full_name'    => $child->full_name,
                'admission_no' => $child->admission_no,
                'class_name'   => $child->classRoom?->name,
                'balance'      => isset($balanceMap[$child->id]) ? (float) $balanceMap[$child->id] : 0.0,
            ];
        });

        $totalBalance = array_reduce($payload->all(), fn($sum, $child) => $sum + $child['balance'], 0);
        $outstandingChildren = count(array_filter($payload->all(), fn($child) => $child['balance'] > 0));

        return response()->json([
            'success' => true,
            'data' => [
                'total_children' => $children->count(),
                'outstanding_children' => $outstandingChildren,
                'total_balance' => $totalBalance,
                'children' => $payload,
            ],
        ]);
    }

    /**
     * Trigger an M-Pesa STK Push to the parent's phone.
     */
    public function initiateStkPush(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'student_id'   => 'required|exists:students,id',
            'phone_number' => 'required|string|min:9|max:15', // e.g., 254712345678 or 0712345678
            'amount'       => 'required|numeric|min:1', // M-Pesa minimum is 1 KES
        ]);

        $student = Student::findOrFail($validated['student_id']);
        $schoolId = $student->school_id;

        $this->authorize('view', $student);

        try {
            // Initiate the push via our service. 
            // We use the student's admission number as the strict Account Reference.
            $response = $this->mpesaService->initiateStkPush(
                schoolId: $schoolId,
                studentId: $student->id,
                phone: $validated['phone_number'],
                amount: (string) $validated['amount'],
                reference: $student->admission_no 
            );

            return response()->json([
                'success' => true,
                'message' => 'Payment request sent to your phone. Please enter your M-Pesa PIN.',
                'merchant_request_id' => $response['MerchantRequestID']
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to initiate M-Pesa payment. Ensure the school payment gateway is configured.',
                'error'   => $e->getMessage()
            ], 422);
        }
    }

    public function publicInitiateStkPush(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'student_admission_no' => 'required|string',
            'school_id'            => 'required|integer',
            'phone_number'        => 'required|string|min:9|max:15',
            'amount'              => 'required|numeric|min:1',
        ]);

        $student = Student::where('admission_no', $validated['student_admission_no'])
            ->where('school_id', $validated['school_id'])
            ->first();

        if (!$student) {
            return response()->json([
                'success' => false,
                'message' => 'Student not found for the provided admission number and school.',
            ], 404);
        }

        try {
            $response = $this->mpesaService->initiateStkPush(
                schoolId: $student->school_id,
                studentId: $student->id,
                phone: $validated['phone_number'],
                amount: (string) $validated['amount'],
                reference: $student->admission_no
            );

            return response()->json([
                'success' => true,
                'message' => 'Payment request sent. Please complete the M-Pesa prompt on the phone.',
                'merchant_request_id' => $response['MerchantRequestID'],
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to initiate public M-Pesa STK push.',
                'error' => $e->getMessage(),
            ], 422);
        }
    }
}