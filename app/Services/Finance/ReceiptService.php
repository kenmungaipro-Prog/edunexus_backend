<?php

namespace App\Services\Finance;

use App\Models\Payment;
use App\Models\Receipt;
use App\Models\FinanceStatuses;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReceiptService
{
    /**
     * Formally issue an un-voidable matching cash desk receipt verification voucher.
     *
     * @param Payment $payment
     * @return Receipt
     */
    public function issue(Payment $payment): Receipt
    {
        return DB::transaction(function () use ($payment) {
            // Idempotency guard check: ensure we do not issue duplicate vouchers
            $existingReceipt = Receipt::where('payment_id', $payment->id)->first();
            if ($existingReceipt) {
                return $existingReceipt;
            }

            $receiptNumber = $this->generateReceiptNumber($payment->school_id);

            // Persist the verifiable accounting receipt artifact
            return Receipt::create([
                'school_id'      => $payment->school_id,
                'payment_id'     => $payment->id,
                'receipt_number' => $receiptNumber,
                'issue_date'     => now()->toDateString(),
                'amount'         => $payment->amount,
                'status'         => FinanceStatuses::RECEIPT_ISSUED,
                'issued_by'      => auth()->id() ?? $payment->received_by,
            ]);
        });
    }

    /**
     * Unique secure receipt format generator.
     */
    private function generateReceiptNumber(int $schoolId): string
    {
        do {
            $number = 'REC-' . $schoolId . '-' . now()->format('Ymd') . '-' . random_int(1000, 9999);
        } while (Receipt::where('school_id', $schoolId)->where('receipt_number', $number)->exists());

        return $number;
    }
}