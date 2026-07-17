<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Models\FeeCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FeeCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $categories = FeeCategory::where('school_id', currentSchoolId())
            ->when($request->boolean('active'), fn ($query) => $query->where('is_active', true))
            ->orderBy('name')
            ->paginate($request->per_page ?? 50);

        return response()->json(['success' => true, 'data' => $categories]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'code' => ['required', 'string', 'max:40', Rule::unique('fee_categories')->where('school_id', currentSchoolId())],
            'description' => 'nullable|string',
            'default_amount' => 'nullable|numeric|min:0',
            'revenue_account_id' => 'nullable|integer',
            'is_active' => 'sometimes|boolean',
        ]);

        $category = FeeCategory::create([
            ...$data,
            'school_id' => currentSchoolId(),
        ]);

        return response()->json(['success' => true, 'data' => $category], 201);
    }

    public function show(FeeCategory $feeCategory): JsonResponse
    {
        $this->authorizeSchool($feeCategory);

        return response()->json(['success' => true, 'data' => $feeCategory]);
    }

    public function update(Request $request, FeeCategory $feeCategory): JsonResponse
    {
        $this->authorizeSchool($feeCategory);

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'code' => ['sometimes', 'required', 'string', 'max:40', Rule::unique('fee_categories')->where('school_id', currentSchoolId())->ignore($feeCategory->id)],
            'description' => 'nullable|string',
            'default_amount' => 'nullable|numeric|min:0',
            'revenue_account_id' => 'nullable|integer',
            'is_active' => 'sometimes|boolean',
        ]);

        $feeCategory->update($data);

        return response()->json(['success' => true, 'data' => $feeCategory->fresh()]);
    }

    public function destroy(FeeCategory $feeCategory): JsonResponse
    {
        $this->authorizeSchool($feeCategory);

        if ($feeCategory->structureItems()->exists()) {
            return response()->json(['success' => false, 'message' => 'Fee category is already used in fee structures.'], 422);
        }

        $feeCategory->delete();

        return response()->json(['success' => true, 'message' => 'Fee category deleted.']);
    }

    private function authorizeSchool(FeeCategory $category): void
    {
        abort_if($category->school_id !== currentSchoolId(), 404);
    }
}
