<?php

namespace App\Services\Accounting;

use App\Models\JournalEntry;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Exception;

class LedgerPostingService
{
    /**
     * Posts a balanced journal entry to the general ledger.
     */
    public function post(array $data, array $lines): JournalEntry
    {
        return DB::transaction(function () use ($data, $lines) {
            $totalDebit = collect($lines)->sum('debit');
            $totalCredit = collect($lines)->sum('credit');

            if (abs($totalDebit - $totalCredit) > 0.001) {
                throw new Exception("Journal entry is not balanced. Debits: $totalDebit, Credits: $totalCredit");
            }

            $entry = JournalEntry::create(array_merge($data, [
                'status' => 'posted'
            ]));

            foreach ($lines as $line) {
                $entry->lines()->create($line);
            }

            return $entry;
        });
    }

    /**
     * Automatically posts an invoice to the General Ledger.
     * Debit: Student Receivables
     * Credit: Fee Revenue
     */
    public function postInvoice(Invoice $invoice): JournalEntry
    {
        // Note: In a real implementation, IDs for control accounts should be 
        // retrieved from a configuration or the ChartOfAccount model.
        $receivableAccountId = $this->getControlAccountId('receivables', $invoice->school_id);
        $revenueAccountId = $invoice->items()->first()->feeCategory->revenue_account_id;

        return $this->post([
            'school_id' => $invoice->school_id,
            'entry_date' => $invoice->issue_date,
            'description' => "Invoice issued: " . $invoice->invoice_number,
            'reference' => $invoice->invoice_number,
            'source_module' => 'finance',
            'source_id' => $invoice->id,
            'created_by' => auth()->id() ?? 1, // Fallback for automated jobs
        ], [
            [
                'chart_of_account_id' => $receivableAccountId,
                'debit' => $invoice->total,
                'credit' => 0,
            ],
            [
                'chart_of_account_id' => $revenueAccountId,
                'debit' => 0,
                'credit' => $invoice->total,
            ]
        ]);
    }

    private function getControlAccountId(string $type, int $schoolId): int
    {
        // Logic to find system-defined control accounts
        return DB::table('chart_of_accounts')
            ->where('school_id', $schoolId)
            ->where('account_code', '1200') // Example code for Receivables
            ->value('id');
    }
}
