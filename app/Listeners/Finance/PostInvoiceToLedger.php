<?php

// Path: app/Listeners/Finance/PostInvoiceToLedger.php

namespace App\Listeners\Finance;

use App\Events\Finance\InvoiceIssued;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use Exception;
use Illuminate\Support\Facades\DB;

class PostInvoiceToLedger
{
    /**
     * Handle the event to post the invoice to the general ledger.
     */
    public function handle(InvoiceIssued $event): void
    {
        $invoice = $event->invoice;

        if ($invoice->posted_to_ledger) {
            return; // Idempotency check: prevent duplicate postings
        }

        DB::transaction(function () use ($invoice) {
            $schoolId = $invoice->school_id;

            // 1. Resolve Accounts Receivable (A/R) Control Account
            $arAccount = ChartOfAccount::where('school_id', $schoolId)
                ->where('account_type', 'asset')
                ->where('is_control_account', true)
                ->first();

            if (!$arAccount) {
                throw new Exception("Accounts Receivable control account is missing for this school.");
            }

            // 2. Create the Journal Entry
            $journalEntry = JournalEntry::create([
                'school_id'     => $schoolId,
                'entry_date'    => $invoice->issue_date ?? now()->format('Y-m-d'),
                'description'   => "Student Billing — Invoice: {$invoice->invoice_number}",
                'reference'     => $invoice->invoice_number,
                'source_module' => 'finance.invoice',
                'source_id'     => $invoice->id,
                'status'        => 'posted',
                'created_by'    => $invoice->created_by ?? 1, // Fallback to system user if null
            ]);

            // 3. DEBIT: Accounts Receivable (Total Invoice Amount)
            $journalEntry->journalEntryLines()->create([
                'chart_of_account_id' => $arAccount->id,
                'debit'               => $invoice->total,
                'credit'              => '0.00',
                'memo'                => "Total billable receivables",
            ]);

            // 4. CREDIT: Revenue Accounts (Iterate through items)
            foreach ($invoice->invoiceItems as $item) {
                // Check item level, fallback to category level, then fallback to a generic revenue account
                $revenueAccountId = $item->revenue_account_id 
                    ?? $item->feeCategory->revenue_account_id 
                    ?? $this->getFallbackRevenueAccount($schoolId);

                $journalEntry->journalEntryLines()->create([
                    'chart_of_account_id' => $revenueAccountId,
                    'debit'               => '0.00',
                    'credit'              => $item->total,
                    'memo'                => "Revenue: {$item->description}",
                ]);
            }

            // 5. Assert strict double-entry balance
            if (!$journalEntry->isBalanced()) {
                throw new Exception("Ledger Error: Invoice {$invoice->invoice_number} generated an unbalanced journal entry.");
            }

            // 6. Mark sub-ledger invoice as posted
            $invoice->update([
                'posted_to_ledger' => true,
                'journal_entry_id' => $journalEntry->id,
            ]);
        });
    }

    protected function getFallbackRevenueAccount(int $schoolId): int
    {
        $account = ChartOfAccount::where('school_id', $schoolId)
            ->where('account_type', 'revenue')
            ->first();

        if (!$account) {
            throw new Exception("No generic revenue account configured to handle unmapped fee categories.");
        }

        return $account->id;
    }
}