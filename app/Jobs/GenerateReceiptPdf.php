<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Models\Receipt;

class GenerateReceiptPdf implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $receiptId) {}

    public function handle()
    {
        $receipt = Receipt::find($this->receiptId);
        if (!$receipt) {
            Log::warning('Receipt not found for PDF generation', ['receipt_id' => $this->receiptId]);
            return;
        }

        $pdfPath = 'receipts/' . $receipt->receipt_number . '.pdf';
        $pdfContent = "Receipt Number: {$receipt->receipt_number}\n" .
            "Amount: {$receipt->payment->amount}\n" .
            "Student ID: {$receipt->payment->student_id}\n" .
            "Date: {$receipt->receipt_date}\n";

        Storage::disk('public')->put($pdfPath, $pdfContent);
        $receipt->update(['pdf_path' => $pdfPath]);

        Log::info('Generated receipt PDF', ['receipt_id' => $this->receiptId, 'pdf' => $pdfPath]);
    }
}
