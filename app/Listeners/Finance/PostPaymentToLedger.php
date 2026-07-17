<?php

// Path: app/Listeners/Finance/PostPaymentToLedger.php

namespace App\Listeners\Finance;

use App\Events\Finance\PaymentReceived;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use Exception;
use Illuminate\Support\Facades\DB;

class PostPaymentToLedger
{
    /**
     * Handle the event to post the received payment to the general ledger.
     */
    public function handle(PaymentReceived $event): void
    {
        $payment = $event->payment;

        if ($payment->posted_to_ledger) {
            return; // Idempotency check
        }

        DB::transaction(function () use ($payment) {
            $schoolId = $payment->school_id;

            // 1. Resolve Asset Destination (Bank/Cash)
            $bankAccount = ChartOfAccount::where('school_id', $schoolId)
                ->where('account_type', 'asset')
                ->where('is_bank_account', true)
                ->first();

            // 2. Resolve Asset Reduction (Accounts Receivable Control)
            $arAccount = ChartOfAccount::where('school_id', $schoolId)
                ->where('account_type', 'asset')
                ->where('is_control_account', true)
                ->first();

            if (!$bankAccount || !$arAccount) {
                $this->createDefaultLedgerAccounts($schoolId);

                $bankAccount = ChartOfAccount::where('school_id', $schoolId)
                    ->where('account_type', 'asset')
                    ->where('is_bank_account', true)
                    ->first();

                $arAccount = ChartOfAccount::where('school_id', $schoolId)
                    ->where('account_type', 'asset')
                    ->where('is_control_account', true)
                    ->first();
            }

            if (!$bankAccount || !$arAccount) {
                throw new Exception("Critical accounts (Bank or A/R) are missing for ledger posting.");
            }

            // 3. Create Journal Entry
            $journalEntry = JournalEntry::create([
                'school_id'     => $schoolId,
                'entry_date'    => $payment->payment_date,
                'description'   => "Payment Collection — Ref: {$payment->payment_number}",
                'reference'     => $payment->payment_number,
                'source_module' => 'finance.payment',
                'source_id'     => $payment->id,
                'status'        => 'posted',
                'created_by'    => $payment->received_by ?? 1,
            ]);

            // 4. DEBIT: Cash/Bank Account (Increasing liquid asset)
            $journalEntry->journalEntryLines()->create([
                'chart_of_account_id' => $bankAccount->id,
                'debit'               => $payment->amount,
                'credit'              => '0.00',
                'memo'                => "Funds received via " . strtoupper($payment->payment_method),
            ]);

            // 5. CREDIT: Accounts Receivable (Decreasing owed debt)
            $journalEntry->journalEntryLines()->create([
                'chart_of_account_id' => $arAccount->id,
                'debit'               => '0.00',
                'credit'              => $payment->amount,
                'memo'                => "Liquidation of receivables",
            ]);

            // 6. Assert strict double-entry balance
            if (!$journalEntry->isBalanced()) {
                throw new Exception("Ledger Error: Payment {$payment->payment_number} generated an unbalanced journal entry.");
            }

            // 7. Mark sub-ledger payment as posted
            $payment->update([
                'posted_to_ledger' => true,
                'journal_entry_id' => $journalEntry->id,
            ]);
        });
    }

    private function createDefaultLedgerAccounts(int $schoolId): void
    {
        $parent = ChartOfAccount::firstOrCreate(
            ['school_id' => $schoolId, 'account_code' => '1000'],
            [
                'parent_account_id' => null,
                'account_name' => 'Current Assets',
                'account_type' => 'asset',
                'normal_balance' => 'debit',
                'currency' => 'KES',
                'is_control_account' => false,
                'is_bank_account' => false,
                'is_system' => true,
                'is_active' => true,
            ]
        );

        ChartOfAccount::firstOrCreate(
            ['school_id' => $schoolId, 'account_code' => '1100'],
            [
                'parent_account_id' => $parent->id,
                'account_name' => 'Cash & Bank',
                'account_type' => 'asset',
                'normal_balance' => 'debit',
                'currency' => 'KES',
                'is_control_account' => false,
                'is_bank_account' => true,
                'is_system' => true,
                'is_active' => true,
            ]
        );

        ChartOfAccount::firstOrCreate(
            ['school_id' => $schoolId, 'account_code' => '1200'],
            [
                'parent_account_id' => $parent->id,
                'account_name' => 'Accounts Receivable',
                'account_type' => 'asset',
                'normal_balance' => 'debit',
                'currency' => 'KES',
                'is_control_account' => true,
                'is_bank_account' => false,
                'is_system' => true,
                'is_active' => true,
            ]
        );
    }
}