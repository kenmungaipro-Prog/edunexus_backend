<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Models\FeeStructure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FeeStructureController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $structures = FeeStructure::with(['classRoom', 'session', 'items.feeCategory'])
            ->where('school_id', currentSchoolId())
            ->when($request->class_id, fn ($query, $value) => $query->where('class_id', $value))
            ->when($request->session_id, fn ($query, $value) => $query->where('session_id', $value))
            ->when($request->status, fn ($query, $value) => $query->where('status', $value))
            ->orderByDesc('created_at')
            ->paginate($request->per_page ?? 20);

        return response()->json(['success' => true, 'data' => $structures]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_id' => 'required|exists:academic_sessions,id',
            'class_id' => 'nullable|exists:class_rooms,id',
            'name' => 'required|string|max:255',
            'billing_period' => 'nullable|string|max:40',
            'currency' => 'nullable|string|size:3',
            'status' => 'nullable|in:draft,active,inactive',
            'effective_from' => 'nullable|date',
            'effective_to' => 'nullable|date|after_or_equal:effective_from',
            'items' => 'required|array|min:1',
            'items.*.fee_category_id' => 'required|exists:fee_categories,id',
            'items.*.description' => 'nullable|string|max:255',
            'items.*.amount' => 'required|numeric|min:0',
            'items.*.is_mandatory' => 'sometimes|boolean',
            'items.*.is_recurring' => 'sometimes|boolean',
            'items.*.revenue_account_id' => 'nullable|integer',
        ]);

        $structure = DB::transaction(function () use ($data) {
            $items = $data['items'];
            unset($data['items']);

            $structure = FeeStructure::create([
                ...$data,
                'school_id' => currentSchoolId(),
                'currency' => strtoupper($data['currency'] ?? 'KES'),
                'billing_period' => $data['billing_period'] ?? 'term',
                'status' => $data['status'] ?? 'draft',
                'created_by' => auth()->id(),
            ]);

            foreach ($items as $item) {
                $structure->items()->create($item);
            }

            return $structure;
        });

        return response()->json(['success' => true, 'data' => $structure->load('items.feeCategory')], 201);
    }

    public function show(FeeStructure $feeStructure): JsonResponse
    {
        $this->authorizeSchool($feeStructure);

        return response()->json(['success' => true, 'data' => $feeStructure->load('classRoom', 'session', 'items.feeCategory')]);
    }

    public function update(Request $request, FeeStructure $feeStructure): JsonResponse
    {
        $this->authorizeSchool($feeStructure);

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'billing_period' => 'nullable|string|max:40',
            'currency' => 'nullable|string|size:3',
            'status' => 'nullable|in:draft,active,inactive',
            'effective_from' => 'nullable|date',
            'effective_to' => 'nullable|date|after_or_equal:effective_from',
            'items' => 'sometimes|array|min:1',
            'items.*.fee_category_id' => 'required_with:items|exists:fee_categories,id',
            'items.*.description' => 'nullable|string|max:255',
            'items.*.amount' => 'required_with:items|numeric|min:0',
            'items.*.is_mandatory' => 'sometimes|boolean',
            'items.*.is_recurring' => 'sometimes|boolean',
            'items.*.revenue_account_id' => 'nullable|integer',
        ]);

        if (isset($data['currency'])) {
            $data['currency'] = strtoupper($data['currency']);
        }

        DB::transaction(function () use ($data, $feeStructure): void {
            $items = $data['items'] ?? null;
            unset($data['items']);

            $feeStructure->update($data);

            if ($items !== null) {
                $feeStructure->items()->delete();

                foreach ($items as $item) {
                    $feeStructure->items()->create([
                        ...$item,
                        'amount' => (float) $item['amount'],
                    ]);
                }
            }
        });

        return response()->json(['success' => true, 'data' => $feeStructure->fresh()->load('items.feeCategory')]);
    }

    public function destroy(FeeStructure $feeStructure): JsonResponse
    {
        $this->authorizeSchool($feeStructure);
        $feeStructure->delete();

        return response()->json(['success' => true, 'message' => 'Fee structure deleted.']);
    }

    private function authorizeSchool(FeeStructure $structure): void
    {
        abort_if($structure->school_id !== currentSchoolId(), 404);
    }
}
