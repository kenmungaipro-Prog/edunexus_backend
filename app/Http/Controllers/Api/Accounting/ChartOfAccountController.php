<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Http\Controllers\Controller;
use App\Models\ChartOfAccount;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class ChartOfAccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $accounts = ChartOfAccount::where('school_id', $request->user()->school_id)
            ->with('parent')
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
            'parent_account_id' => 'nullable|exists:chart_of_accounts,id',
            'is_control_account' => 'boolean',
            'is_bank_account' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $validated['school_id'] = $request->user()->school_id;

        $account = ChartOfAccount::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Account created successfully',
            'data' => $account->load('parent'),
        ], 201);
    }

    public function show(Request $request, ChartOfAccount $chartOfAccount): JsonResponse
    {
        if ($chartOfAccount->school_id !== $request->user()->school_id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $chartOfAccount->load(['parent', 'children']),
        ]);
    }

    public function update(Request $request, ChartOfAccount $chartOfAccount): JsonResponse
    {
        if ($chartOfAccount->school_id !== $request->user()->school_id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        if ($chartOfAccount->is_system) {
            return response()->json([
                'success' => false,
                'message' => 'System accounts cannot be modified',
            ], 422);
        }

        $validated = $request->validate([
            'account_name' => 'sometimes|string|max:255',
            'parent_account_id' => 'nullable|exists:chart_of_accounts,id',
            'is_control_account' => 'boolean',
            'is_bank_account' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $chartOfAccount->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Account updated successfully',
            'data' => $chartOfAccount->fresh()->load('parent'),
        ]);
    }

    public function destroy(Request $request, ChartOfAccount $chartOfAccount): JsonResponse
    {
        if ($chartOfAccount->school_id !== $request->user()->school_id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        if ($chartOfAccount->is_system) {
            return response()->json([
                'success' => false,
                'message' => 'System accounts cannot be deleted',
            ], 422);
        }

        if ($chartOfAccount->children()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete account with sub-accounts',
            ], 422);
        }

        if ($chartOfAccount->journalEntryLines()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete account with journal entries',
            ], 422);
        }

        $chartOfAccount->delete();

        return response()->json([
            'success' => true,
            'message' => 'Account deleted successfully',
        ]);
    }

    public function tree(Request $request): JsonResponse
    {
        $accounts = ChartOfAccount::where('school_id', $request->user()->school_id)
            ->whereNull('parent_account_id')
            ->with('children')
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
            ->orderBy('account_code')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $accounts,
        ]);
    }
}