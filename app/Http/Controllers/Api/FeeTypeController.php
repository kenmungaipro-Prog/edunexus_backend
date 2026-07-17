<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FeeType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeeTypeController extends Controller
{
    public function index(): JsonResponse
    {
        $feeTypes = FeeType::where(function ($query) {
                $query->where('school_id', currentSchoolId())->orWhereNull('school_id');
            })
            ->orderBy('name')
            ->get();

        return response()->json(['success' => true, 'data' => $feeTypes]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
            'frequency' => 'required|string|max:50',
            'description' => 'nullable|string',
        ]);

        $feeType = FeeType::create([
            ...$data,
            'school_id' => currentSchoolId(),
        ]);

        return response()->json(['success' => true, 'data' => $feeType], 201);
    }

    public function update(Request $request, FeeType $feeType): JsonResponse
    {
        $this->ensureSchoolOwnsFeeType($feeType);

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'amount' => 'sometimes|required|numeric|min:0',
            'frequency' => 'sometimes|required|string|max:50',
            'description' => 'nullable|string',
        ]);

        $feeType->update($data);

        return response()->json(['success' => true, 'data' => $feeType->fresh()]);
    }

    public function destroy(FeeType $feeType): JsonResponse
    {
        $this->ensureSchoolOwnsFeeType($feeType);

        if ($feeType->fees()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Fee type cannot be deleted because it has fee records.',
            ], 422);
        }

        $feeType->delete();

        return response()->json(['success' => true, 'message' => 'Fee type deleted.']);
    }

    private function ensureSchoolOwnsFeeType(FeeType $feeType): void
    {
        abort_if($feeType->school_id !== currentSchoolId(), 404);
    }
}
