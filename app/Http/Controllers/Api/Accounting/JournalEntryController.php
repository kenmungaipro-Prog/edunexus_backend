<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Http\Controllers\Controller;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class JournalEntryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = JournalEntry::where('school_id', $request->user()->school_id)
            ->with(['creator', 'lines.account'])
            ->orderBy('entry_date', 'desc');

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('from_date')) {
            $query->where('entry_date', '>=', $request->from_date);
        }

        if ($request->has('to_date')) {
            $query->where('entry_date', '<=', $request->to_date);
        }

        $entries = $query->limit(100)->get();

        return response()->json([
            'success' => true,
            'data' => $entries,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entry_date' => 'required|date',
            'description' => 'required|string|max:500',
            'reference' => 'nullable|string|max:100',
            'source_module' => 'nullable|string|max:50',
            'source_id' => 'nullable|integer',
            'lines' => 'required|array|min:2',
            'lines.*.chart_of_account_id' => 'required|exists:chart_of_accounts,id',
            'lines.*.debit' => 'required|numeric|min:0',
            'lines.*.credit' => 'required|numeric|min:0',
            'lines.*.memo' => 'nullable|string|max:255',
        ]);

        // Validate debits equal credits
        $totalDebit = collect($validated['lines'])->sum('debit');
        $totalCredit = collect($validated['lines'])->sum('credit');

        if (bccomp($totalDebit, $totalCredit, 2) !== 0) {
            return response()->json([
                'success' => false,
                'message' => 'Debits must equal credits',
                'debit_total' => $totalDebit,
                'credit_total' => $totalCredit,
            ], 422);
        }

        $entry = DB::transaction(function () use ($request, $validated) {
            $journalEntry = JournalEntry::create([
                'school_id' => $request->user()->school_id,
                'entry_date' => $validated['entry_date'],
                'description' => $validated['description'],
                'reference' => $validated['reference'] ?? null,
                'source_module' => $validated['source_module'] ?? null,
                'source_id' => $validated['source_id'] ?? null,
                'status' => 'draft',
                'created_by' => $request->user()->id,
            ]);

            foreach ($validated['lines'] as $line) {
                if ($line['debit'] > 0 || $line['credit'] > 0) {
                    JournalEntryLine::create([
                        'journal_entry_id' => $journalEntry->id,
                        'chart_of_account_id' => $line['chart_of_account_id'],
                        'debit' => $line['debit'],
                        'credit' => $line['credit'],
                        'memo' => $line['memo'] ?? null,
                    ]);
                }
            }

            return $journalEntry;
        });

        return response()->json([
            'success' => true,
            'message' => 'Journal entry created successfully',
            'data' => $entry->load(['creator', 'lines.account']),
        ], 201);
    }

    public function show(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        if ($journalEntry->school_id !== $request->user()->school_id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $journalEntry->load(['creator', 'lines.account']),
        ]);
    }

    public function submit(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        if ($journalEntry->school_id !== $request->user()->school_id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        if ($journalEntry->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Only draft entries can be submitted',
            ], 422);
        }

        if (!$journalEntry->isBalanced()) {
            return response()->json([
                'success' => false,
                'message' => 'Entry must be balanced before submission',
            ], 422);
        }

        $journalEntry->update(['status' => 'posted']);

        return response()->json([
            'success' => true,
            'message' => 'Journal entry submitted successfully',
            'data' => $journalEntry->fresh(['creator', 'lines.account']),
        ]);
    }

    public function approve(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        if ($journalEntry->school_id !== $request->user()->school_id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        if ($journalEntry->created_by === $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot approve your own journal entry',
            ], 422);
        }

        if ($journalEntry->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Only draft entries can be approved',
            ], 422);
        }

        $journalEntry->update(['status' => 'posted']);

        return response()->json([
            'success' => true,
            'message' => 'Journal entry approved and posted',
            'data' => $journalEntry->fresh(['creator', 'lines.account']),
        ]);
    }

    public function reverse(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        if ($journalEntry->school_id !== $request->user()->school_id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        if ($journalEntry->status !== 'posted') {
            return response()->json([
                'success' => false,
                'message' => 'Only posted entries can be reversed',
            ], 422);
        }

        $validated = $request->validate([
            'reversal_reason' => 'required|string|max:500',
        ]);

        DB::transaction(function () use ($journalEntry, $request, $validated) {
            // Create reversal entry
            $reversal = JournalEntry::create([
                'school_id' => $journalEntry->school_id,
                'entry_date' => now()->toDateString(),
                'description' => 'REVERSAL: ' . $journalEntry->description,
                'reference' => $journalEntry->reference,
                'source_module' => $journalEntry->source_module,
                'source_id' => $journalEntry->source_id,
                'status' => 'posted',
                'created_by' => $request->user()->id,
            ]);

            // Reverse each line
            foreach ($journalEntry->lines as $line) {
                JournalEntryLine::create([
                    'journal_entry_id' => $reversal->id,
                    'chart_of_account_id' => $line->chart_of_account_id,
                    'debit' => $line->credit,
                    'credit' => $line->debit,
                    'memo' => 'Reversal of ' . ($line->memo ?? 'entry'),
                ]);
            }

            // Mark original as reversed
            $journalEntry->update(['status' => 'reversed']);
        });

        return response()->json([
            'success' => true,
            'message' => 'Journal entry reversed successfully',
        ]);
    }

    public function destroy(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        if ($journalEntry->school_id !== $request->user()->school_id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        if ($journalEntry->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Only draft entries can be deleted',
            ], 422);
        }

        $journalEntry->delete();

        return response()->json([
            'success' => true,
            'message' => 'Journal entry deleted successfully',
        ]);
    }

    public function trialBalance(Request $request): JsonResponse
    {
        $fromDate = $request->get('from_date', now()->startOfYear()->toDateString());
        $toDate = $request->get('to_date', now()->toDateString());

        $accounts = ChartOfAccount::where('school_id', $request->user()->school_id)
            ->where('is_active', true)
            ->with(['journalEntryLines' => function ($q) use ($fromDate, $toDate) {
                $q->whereHas('journalEntry', function ($entry) use ($fromDate, $toDate) {
                    $entry->where('status', 'posted')
                        ->whereBetween('entry_date', [$fromDate, $toDate]);
                });
            }])
            ->get()
            ->map(function ($account) {
                $totalDebit = $account->journalEntryLines->sum('debit');
                $totalCredit = $account->journalEntryLines->sum('credit');

                $balance = $account->normal_balance === 'debit'
                    ? $totalDebit - $totalCredit
                    : $totalCredit - $totalDebit;

                return [
                    'id' => $account->id,
                    'account_code' => $account->account_code,
                    'account_name' => $account->account_name,
                    'account_type' => $account->account_type,
                    'normal_balance' => $account->normal_balance,
                    'debit' => $totalDebit,
                    'credit' => $totalCredit,
                    'balance' => $balance,
                ];
            });

        $totalDebits = $accounts->sum('debit');
        $totalCredits = $accounts->sum('credit');

        return response()->json([
            'success' => true,
            'data' => [
                'accounts' => $accounts,
                'total_debit' => $totalDebits,
                'total_credit' => $totalCredits,
                'is_balanced' => bccomp($totalDebits, $totalCredits, 2) === 0,
                'from_date' => $fromDate,
                'to_date' => $toDate,
            ],
        ]);
    }
}