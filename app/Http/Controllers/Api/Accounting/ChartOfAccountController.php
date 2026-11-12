<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Http\Controllers\Controller;
use App\Models\ChartOfAccount;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ChartOfAccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $accounts = ChartOfAccount::where('school_id', $request->user()->school_id)
            ->with('parent')
            ->with('children.children')
            ->withCount('children')
            ->orderBy('account_type')
            ->orderBy('sort_order')
            ->orderBy('account_code')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $accounts,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account_code' => [
                'required',
                'string',
                'max:20',
                Rule::unique('chart_of_accounts')->where('school_id', $request->user()->school_id),
            ],
            'account_name' => 'required|string|max:255',
            'account_type' => 'required|in:asset,liability,equity,revenue,expense',
            'normal_balance' => 'required|in:debit,credit',
            'parent_account_id' => 'nullable|integer',
            'is_control_account' => 'boolean',
            'is_bank_account' => 'boolean',
            'is_active' => 'boolean',
        ]);

        if (isset($validated['parent_account_id']) && !$this->isValidParent(
            (int) $validated['parent_account_id'],
            (int) $request->user()->school_id,
            $validated['account_type'],
            null
        )) {
            return response()->json([
                'success' => false,
                'message' => 'A sub-account must belong to an account of the same type in your school, and the hierarchy cannot exceed three levels.',
                'errors' => ['parent_account_id' => ['Select a valid parent account that keeps the hierarchy within three levels.']],
            ], 422);
        }

        $validated['school_id'] = $request->user()->school_id;
        $validated['sort_order'] = ChartOfAccount::where('school_id', $validated['school_id'])
            ->where('parent_account_id', $validated['parent_account_id'] ?? null)
            ->max('sort_order') + 1;

        $account = ChartOfAccount::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Account created successfully',
            'data' => $account->load('parent'),
        ], 201);
    }

    public function show(Request $request, ChartOfAccount $account): JsonResponse
    {
        if ((int) $account->school_id !== (int) $request->user()->school_id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $account->load(['parent', 'children.children']),
        ]);
    }

    public function update(Request $request, ChartOfAccount $account): JsonResponse
    {
        if ((int) $account->school_id !== (int) $request->user()->school_id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        if ($account->is_system) {
            return response()->json([
                'success' => false,
                'message' => 'System accounts cannot be modified',
            ], 422);
        }

        $validated = $request->validate([
            'account_name' => 'sometimes|string|max:255',
            'parent_account_id' => 'sometimes|nullable|integer',
            'is_control_account' => 'boolean',
            'is_bank_account' => 'boolean',
            'is_active' => 'boolean',
        ]);

        if (array_key_exists('parent_account_id', $validated) && $validated['parent_account_id'] !== null) {
            if ((int) $validated['parent_account_id'] === $account->id
                || !$this->isValidParent(
                    (int) $validated['parent_account_id'],
                    (int) $account->school_id,
                    $account->account_type,
                    $account
                )) {
                return response()->json([
                    'success' => false,
                    'message' => 'A sub-account must belong to an account of the same type in your school, and the hierarchy cannot exceed three levels.',
                    'errors' => ['parent_account_id' => ['Select a valid parent account that keeps the hierarchy within three levels.']],
                ], 422);
            }
        }

        if (array_key_exists('parent_account_id', $validated)
            && ($validated['parent_account_id'] === null
                ? $account->parent_account_id !== null
                : (int) $validated['parent_account_id'] !== (int) $account->parent_account_id)) {
            $validated['sort_order'] = ChartOfAccount::where('school_id', $account->school_id)
                ->where('parent_account_id', $validated['parent_account_id'])
                ->max('sort_order') + 1;
        }

        $account->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Account updated successfully',
            'data' => $account->fresh()->load('parent'),
        ]);
    }

    public function destroy(Request $request, ChartOfAccount $account): JsonResponse
    {
        if ((int) $account->school_id !== (int) $request->user()->school_id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        if ($account->is_system) {
            return response()->json([
                'success' => false,
                'message' => 'System accounts cannot be deleted',
            ], 422);
        }

        if ($account->children()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete account with sub-accounts',
            ], 422);
        }

        if ($account->journalEntryLines()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete account with journal entries',
            ], 422);
        }

        $account->delete();

        return response()->json([
            'success' => true,
            'message' => 'Account deleted successfully',
        ]);
    }

    public function tree(Request $request): JsonResponse
    {
        $accounts = ChartOfAccount::where('school_id', $request->user()->school_id)
            ->whereNull('parent_account_id')
            ->with('children.children')
            ->orderBy('account_type')
            ->orderBy('sort_order')
            ->orderBy('account_code')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $accounts,
        ]);
    }

    public function byType(Request $request, string $type): JsonResponse
    {
        $validTypes = ['asset', 'liability', 'equity', 'revenue', 'expense'];
        if (!in_array($type, $validTypes)) {
            return response()->json(['success' => false, 'message' => 'Invalid account type'], 422);
        }

        $accounts = ChartOfAccount::where('school_id', $request->user()->school_id)
            ->where('account_type', $type)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('account_code')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $accounts,
        ]);
    }

    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'parent_account_id' => ['nullable', 'integer'],
            'account_type' => ['required', 'in:asset,liability,equity,revenue,expense'],
            'account_ids' => ['required', 'array', 'min:1'],
            'account_ids.*' => ['required', 'integer', 'distinct'],
        ]);
        $schoolId = (int) $request->user()->school_id;
        $parentId = $validated['parent_account_id'] ?? null;

        if ($parentId !== null && !ChartOfAccount::whereKey($parentId)
            ->where('school_id', $schoolId)
            ->where('account_type', $validated['account_type'])
            ->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'The selected parent account is invalid.',
            ], 422);
        }

        $siblings = ChartOfAccount::where('school_id', $schoolId)
            ->where('account_type', $validated['account_type'])
            ->where('parent_account_id', $parentId)
            ->orderBy('sort_order')
            ->orderBy('account_code')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $orderedIds = array_map('intval', $validated['account_ids']);

        if (count($siblings) !== count($orderedIds)
            || array_diff($siblings, $orderedIds)
            || array_diff($orderedIds, $siblings)) {
            return response()->json([
                'success' => false,
                'message' => 'The account order is out of date. Refresh the chart and try again.',
            ], 422);
        }

        DB::transaction(function () use ($orderedIds, $schoolId) {
            foreach ($orderedIds as $order => $id) {
                ChartOfAccount::where('school_id', $schoolId)
                    ->whereKey($id)
                    ->update(['sort_order' => $order]);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Account order saved.',
        ]);
    }

    private function isValidParent(int $parentId, int $schoolId, string $accountType, ?ChartOfAccount $account): bool
    {
        $parent = ChartOfAccount::whereKey($parentId)
            ->where('school_id', $schoolId)
            ->where('account_type', $accountType)
            ->first();

        if (!$parent || ($account && $this->isDescendantOf($parent, $account))) {
            return false;
        }

        $parentDepth = $this->depth($parent);
        $subtreeHeight = $account ? $this->subtreeHeight($account) : 0;

        return $parentDepth + 1 + $subtreeHeight <= 2;
    }

    private function depth(ChartOfAccount $account): int
    {
        $depth = 0;
        $parentId = $account->parent_account_id;
        while ($parentId !== null) {
            $parentId = ChartOfAccount::whereKey($parentId)->value('parent_account_id');
            $depth++;
        }

        return $depth;
    }

    private function subtreeHeight(ChartOfAccount $account): int
    {
        $childHeights = $account->children()->get()->map(
            fn (ChartOfAccount $child) => $this->subtreeHeight($child)
        );

        return $childHeights->isEmpty() ? 0 : 1 + $childHeights->max();
    }

    private function isDescendantOf(ChartOfAccount $candidate, ChartOfAccount $account): bool
    {
        $parentId = $candidate->parent_account_id;
        while ($parentId !== null) {
            if ((int) $parentId === (int) $account->id) {
                return true;
            }
            $parentId = ChartOfAccount::whereKey($parentId)->value('parent_account_id');
        }

        return false;
    }
}