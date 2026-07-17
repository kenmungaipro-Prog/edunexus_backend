<?php
// Path: app/Services/Finance/InvoiceGenerationService.php

namespace App\Services\Finance;

use App\Models\FinanceStatuses;
use App\Models\Invoice;
use Exception;
use Illuminate\Support\Facades\DB;

class InvoiceGenerationService
{
    /**
     * Create a new draft invoice and compute all line item totals using exact precision.
     */
    public function createInvoice(array $data, int $schoolId, int $userId): Invoice
    {
        return DB::transaction(function () use ($data, $schoolId, $userId) {
            // 1. Generate Invoice Number (e.g., INV-2026-00001)
            $count = Invoice::where('school_id', $schoolId)->lockForUpdate()->count() + 1;
            $invoiceNumber = 'INV-' . date('Y') . '-' . str_pad($count, 5, '0', STR_PAD_LEFT);

            $subtotal = '0.00';
            $discountTotal = '0.00';
            $itemsData = [];

            // 2. Pre-calculate line items to guarantee perfect decimal math
            foreach ($data['items'] as $item) {
                $qty      = (string) ($item['quantity'] ?? '1.00');
                $price    = (string) $item['unit_price'];
                $discount = (string) ($item['discount_amount'] ?? '0.00');

                // lineTotal = (qty * price) - discount
                $grossLineTotal = bcmul($qty, $price, 2);
                $netLineTotal   = bcsub($grossLineTotal, $discount, 2);

                $subtotal      = bcadd($subtotal, $grossLineTotal, 2);
                $discountTotal = bcadd($discountTotal, $discount, 2);

                $itemsData[] = [
                    'fee_category_id'    => $item['fee_category_id'] ?? null,
                    'description'        => $item['description'],
                    'quantity'           => $qty,
                    'unit_price'         => $price,
                    'discount_amount'    => $discount,
                    'total'              => $netLineTotal,
                    'revenue_account_id' => $item['revenue_account_id'] ?? null,
                ];
            }

            $waiverTotal  = (string) ($data['waiver_total'] ?? '0.00');
            $penaltyTotal = (string) ($data['penalty_total'] ?? '0.00');

            // 3. Invoice Total = Subtotal - Discount - Waiver + Penalty
            $totalWithoutPenalty = bcsub(bcsub($subtotal, $discountTotal, 2), $waiverTotal, 2);
            $finalTotal = bcadd($totalWithoutPenalty, $penaltyTotal, 2);

            // 4. Persist the Invoice (Draft State)
            $invoice = Invoice::create([
                'school_id'      => $schoolId,
                'student_id'     => $data['student_id'],
                'session_id'     => $data['session_id'] ?? null,
                'class_id'       => $data['class_id'] ?? null,
                'invoice_number' => $invoiceNumber,
                'issue_date'     => $data['issue_date'] ?? null,
                'due_date'       => $data['due_date'] ?? null,
                'currency'       => $data['currency'] ?? 'KES',
                'subtotal'       => $subtotal,
                'discount_total' => $discountTotal,
                'waiver_total'   => $waiverTotal,
                'penalty_total'  => $penaltyTotal,
                'total'          => $finalTotal,
                'amount_paid'    => '0.00',
                'balance'        => $finalTotal,
                'status'         => FinanceStatuses::INVOICE_DRAFT,
                'created_by'     => $userId,
            ]);

            foreach ($itemsData as $itemData) {
                $invoice->invoiceItems()->create($itemData);
            }

            return $invoice->load('invoiceItems');
        });
    }

    /**
     * Lock the invoice, update the student's running balance, and prepare for Ledger posting.
     */
    public function issueInvoice(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice) {
            if ($invoice->status !== FinanceStatuses::INVOICE_DRAFT) {
                throw new Exception("Only draft invoices can be issued.");
            }

            $invoice->update([
                'status'     => FinanceStatuses::INVOICE_ISSUED,
                'issue_date' => $invoice->issue_date ?? now()->format('Y-m-d'),
            ]);

            // Update fast-read student balances and apply any existing student credit to this invoice.
            $student = $invoice->student()->first();
            if ($student) {
                app(PaymentAllocationService::class)->allocateUnallocatedPaymentsForStudent($student->id, $invoice->currency);
                app(StudentBalanceService::class)->recalculate($student);
            }

            // Broadcast event to trigger LedgerPostingService asynchronously
            // event(new \App\Events\Finance\InvoiceIssued($invoice));

            return $invoice;
        });
    }

    /**
     * Update the denormalized student finance ledger.
     */
    protected function updateStudentBalance(Invoice $invoice): void
    {
        $balanceRecord = StudentFinanceBalance::firstOrCreate(
            ['school_id' => $invoice->school_id, 'student_id' => $invoice->student_id, 'currency' => $invoice->currency],
            ['opening_balance' => '0.00', 'invoiced_total' => '0.00', 'paid_total' => '0.00', 'balance' => '0.00']
        );

        $newInvoicedTotal = bcadd((string) $balanceRecord->invoiced_total, (string) $invoice->total, 2);
        
        // Total Owed = Opening Balance + All Invoices
        $totalOwed = bcadd((string) $balanceRecord->opening_balance, $newInvoicedTotal, 2);
        
        // Current Balance = Total Owed - Total Paid
        $newBalance = bcsub($totalOwed, (string) $balanceRecord->paid_total, 2);

        $balanceRecord->update([
            'invoiced_total'  => $newInvoicedTotal,
            'balance'         => $newBalance,
            'last_updated_at' => now(),
        ]);
    }
}