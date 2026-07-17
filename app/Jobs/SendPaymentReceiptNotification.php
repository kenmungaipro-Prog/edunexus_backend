<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Mail;
use App\Models\Receipt;
use App\Notifications\PaymentReceiptNotification;
use App\Services\SmsService;
use App\Models\User;
use App\Models\ParentProfile;

class SendPaymentReceiptNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $receiptId) {}

    public function handle()
    {
        $receipt = Receipt::with('payment.student')->find($this->receiptId);
        if (!$receipt) {
            Log::warning('Receipt not found for notification', ['receipt_id' => $this->receiptId]);
            return;
        }

        // Notify system recipient (who received the payment)
        $recipient = $receipt->payment->receivedBy;
        if ($recipient) {
            $recipient->notify(new PaymentReceiptNotification($receipt));
            Log::info('Payment receipt notification queued', ['receipt_id' => $receipt->id, 'recipient_id' => $recipient->id]);
        }

        // Notify parent via email and SMS
        $student = $receipt->payment->student;
        if ($student) {
            // Parent user (for email)
            $parentUser = $student->parent;
            if ($parentUser && $parentUser->email) {
                Notification::route('mail', $parentUser->email)->notify(new PaymentReceiptNotification($receipt));
                Log::info('Parent emailed receipt', ['receipt_id' => $receipt->id, 'parent_id' => $parentUser->id]);
            }

            // Parent phone via ParentProfile (for SMS)
            $parentProfile = $student->parentProfile;
            $smsService = app()->make(SmsService::class);
            if ($parentProfile && $parentProfile->phone) {
                $smsMessage = "Payment received: {$receipt->receipt_number} - Amount: {$receipt->payment->amount} KES. Thank you.";
                $smsService->send($parentProfile->phone, $smsMessage);
                Log::info('Parent SMS sent for receipt', ['receipt_id' => $receipt->id, 'phone' => $parentProfile->phone]);
            }
        }

        // Notify school admins (email)
        $schoolId = $receipt->school_id;
        $admins = User::where('school_id', $schoolId)->whereIn('role', ['admin', 'accountant', 'superadmin'])->get();
        foreach ($admins as $admin) {
            if ($admin->email) {
                Notification::route('mail', $admin->email)->notify(new PaymentReceiptNotification($receipt));
            }
        }
    }
}
