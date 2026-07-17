<?php

namespace App\Services\Finance;

use App\Events\Finance\PaymentReceived;
use App\Listeners\Finance\PostPaymentToLedger;
use App\Models\FinanceStatuses;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Receipt;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

class PaymentAllocationService
{
    /**
     * Public entry to accept raw payment data, persist as `payments`, allocate, update balances, and generate receipt.
     */
    public function collectAndAllocate(array $data, int $schoolId, int $userId): Payment
    {
        return DB::transaction(function () use ($data, $schoolId, $userId) {
            $amount = (string) $data['amount'];

            // 1. Generate Payment Number
            $count = Payment::where('school_id', $schoolId)->lockForUpdate()->count() + 1;
            $paymentNumber = 'PMT-' . date('Y') . '-' . str_pad($count, 5, '0', STR_PAD_LEFT);

            // 2. Create the Payment Record
            $payment = Payment::create([
                'school_id'               => $schoolId,
                'student_id'              => $data['student_id'],
                'payment_number'          => $paymentNumber,
                'payment_method'          => $data['payment_method'] ?? 'mpesa',
                'currency'                => $data['currency'] ?? 'KES',
                'amount'                  => $amount,
                'payment_date'            => $data['payment_date'] ?? now(),
                'reference_number'        => $data['reference_number'] ?? null,
                'external_transaction_id' => $data['external_transaction_id'] ?? null,
                'payer_name'              => $data['payer_name'] ?? null,
                'payer_phone'             => $data['payer_phone'] ?? null,
                'status'                  => FinanceStatuses::PAYMENT_SUCCESSFUL,
                'received_by'             => $userId,
                'posted_to_ledger'        => false,
            ]);

            // 3. Allocation (FIFO)
            if ($data['auto_allocate'] ?? true) {
                $this->allocate($payment);
            }

            // 4. Recalculate the student balance so credits and current-term dues stay accurate.
            $student = Student::find((int) $payment->student_id);
            if ($student) {
                app(StudentBalanceService::class)->recalculate($student);
            }

            // 5. Generate receipt
            $receipt = $payment->receipt()->create([
                'school_id'      => $schoolId,
                'receipt_number' => 'RCP-' . date('Y') . '-' . str_pad(Receipt::where('school_id', $schoolId)->count() + 1, 5, '0', STR_PAD_LEFT),
                'receipt_date'   => $payment->payment_date,
                'issued_by'      => $userId,
                'status'         => FinanceStatuses::RECEIPT_ISSUED,
            ]);

            // Dispatch jobs for PDF and notification (sync queue will run immediately in testing)
            \App\Jobs\GenerateReceiptPdf::dispatch($receipt->id);
            \App\Jobs\SendPaymentReceiptNotification::dispatch($receipt->id);

            // Broadcast PaymentReceived event so any listeners (ledger posting, integrations) can react.
            // Also call the ledger posting listener directly to ensure the general ledger is updated
            // even if EventServiceProvider is not configured in some installations.
            try {
                event(new PaymentReceived($payment));
                // Attempt direct ledger posting (idempotent via posted_to_ledger flag)
                $ledgerPoster = app()->make(PostPaymentToLedger::class);
                $ledgerPoster->handle(new PaymentReceived($payment));
            } catch (\Throwable $e) {
                // Log but do not abort the payment flow
                \Illuminate\Support\Facades\Log::error('Post-payment events failed: ' . $e->getMessage(), ['payment_id' => $payment->id]);
            }

            return $payment->load(['allocations.invoice', 'receipt']);
        });
    }

    /**
     * Atomically match a payment across outstanding open liabilities using FIFO order.
     *
     * @param Payment $payment
     * @return void
     */
    public function allocate(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            // Guard clause to ensure we only process successful cash inflows
            if (in_array($payment->status, [FinanceStatuses::PAYMENT_FAILED, FinanceStatuses::PAYMENT_REVERSED, FinanceStatuses::PAYMENT_REFUNDED])) {
                return;
            }

            // 1. Pull chronological open balances for the targeted student
            $openInvoices = Invoice::where('student_id', $payment->student_id)
                ->whereIn('status', FinanceStatuses::invoicePayableStatuses())
                ->orderBy('issue_date', 'asc')
                ->orderBy('id', 'asc')
                ->get();

            $unallocatedFunds = $this->getUnallocatedAmount($payment);

            foreach ($openInvoices as $invoice) {
                if ($unallocatedFunds <= 0) {
                    break;
                }

                $invoiceRemainingLiability = (float) $invoice->balance;

                if ($invoiceRemainingLiability <= 0) {
                    continue;
                }

                // Determine exact fraction match to absorb
                $allocationAmount = min($unallocatedFunds, $invoiceRemainingLiability);

                // 2. Generate transaction allocation junction trace
                PaymentAllocation::create([
                    'payment_id'      => $payment->id,
                    'invoice_id'      => $invoice->id,
                    'amount_allocated' => $allocationAmount,
                    'allocated_at'    => now(),
                ]);

                // 3. Increment absolute values on invoice record
                $newAmountPaid = (float) $invoice->amount_paid + $allocationAmount;
                $newBalance = (float) $invoice->balance - $allocationAmount;
                
                $invoice->amount_paid = $newAmountPaid;
                $invoice->balance = $newBalance;

                // Adjust payment states dynamically based on completion
                if ($invoice->balance <= 0) {
                    $invoice->status = FinanceStatuses::INVOICE_PAID;
                } else {
                    $invoice->status = FinanceStatuses::INVOICE_PARTIALLY_PAID;
                }

                $invoice->save();

                $unallocatedFunds -= $allocationAmount;
            }

            // 4. Update parent collection visibility attributes
            if ($unallocatedFunds <= 0) {
                $payment->status = FinanceStatuses::PAYMENT_FULLY_ALLOCATED;
            } else {
                $payment->status = FinanceStatuses::PAYMENT_PARTIALLY_ALLOCATED;
            }
            
            $payment->save();
        });
    }

    public function allocateUnallocatedPaymentsForStudent(int $studentId, string $currency = 'KES'): void
    {
        $payments = Payment::where('student_id', $studentId)
            ->where('currency', $currency)
            ->whereIn('status', [
                FinanceStatuses::PAYMENT_SUCCESSFUL,
                FinanceStatuses::PAYMENT_PARTIALLY_ALLOCATED,
            ])
            ->orderBy('payment_date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        foreach ($payments as $payment) {
            if ($this->getUnallocatedAmount($payment) <= 0) {
                continue;
            }

            $this->allocate($payment);
        }
    }

    public function refundUnallocatedPayment(Payment $payment): float
    {
        $unallocatedAmount = $this->getUnallocatedAmount($payment);

        if ($unallocatedAmount <= 0) {
            return 0.0;
        }

        $payment->amount = bcsub((string) $payment->amount, (string) $unallocatedAmount, 2);
        $allocatedAmount = (float) $payment->allocations()->sum('amount_allocated');

        if ($allocatedAmount <= 0) {
            $payment->status = FinanceStatuses::PAYMENT_REFUNDED;
        } else {
            $payment->status = FinanceStatuses::PAYMENT_FULLY_ALLOCATED;
        }

        $payment->save();

        return $unallocatedAmount;
    }

    protected function getUnallocatedAmount(Payment $payment): float
    {
        $allocated = (float) $payment->allocations()->sum('amount_allocated');

        return max(0, (float) $payment->amount - $allocated);
    }

    protected function updateStudentBalance(int $studentId, int $schoolId, string $paidAmount): void
    {
        // Minimal implementation: keep or extend existing StudentFinanceBalance model usage
        $balanceRecord = \App\Models\StudentFinanceBalance::firstOrCreate(
            ['school_id' => $schoolId, 'student_id' => $studentId, 'currency' => 'KES'],
            ['opening_balance' => '0.00', 'invoiced_total' => '0.00', 'paid_total' => '0.00', 'balance' => '0.00']
        );

        $newPaidTotal = bcadd((string) $balanceRecord->paid_total, $paidAmount, 2);
        // Balance = (Opening + Invoiced) - Paid
        $totalOwed = bcadd((string) $balanceRecord->opening_balance, (string) $balanceRecord->invoiced_total, 2);
        $newBalance = bcsub($totalOwed, $newPaidTotal, 2);

        $balanceRecord->update([
            'paid_total'      => $newPaidTotal,
            'balance'         => $newBalance,
            'last_updated_at' => now(),
        ]);
    }
}