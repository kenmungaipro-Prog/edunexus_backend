<?php

namespace App\Notifications;

use App\Models\Receipt;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Messages\MailMessage;

class PaymentReceiptNotification extends Notification
{
    use Queueable;

    public function __construct(public Receipt $receipt)
    {
    }

    public function via($notifiable): array
    {
        $channels = ['database'];
        if (isset($notifiable->email) && $notifiable->email) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toDatabase($notifiable): array
    {
        return [
            'receipt_id' => $this->receipt->id,
            'payment_id' => $this->receipt->payment_id,
            'receipt_number' => $this->receipt->receipt_number,
            'amount' => $this->receipt->payment->amount,
            'student_id' => $this->receipt->payment->student_id,
            'issued_by' => $this->receipt->issued_by,
            'message' => 'A payment receipt has been generated.',
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        $student = $this->receipt->payment->student;
        $amount = $this->receipt->payment->amount;
        $receiptNo = $this->receipt->receipt_number;

        return (new MailMessage)
            ->subject("Receipt: {$receiptNo} - Payment Received")
            ->greeting('Hello,')
            ->line("A payment of KES {$amount} has been received for {$student?->full_name}")
            ->line("Receipt Number: {$receiptNo}")
            ->line('You can view the receipt in your school portal.')
            ->line('Thank you for using our services.');
    }

    public function toArray($notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
