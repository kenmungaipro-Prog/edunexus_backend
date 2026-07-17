<?php

namespace App\Jobs;

use App\Services\Payments\MpesaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessMpesaCallback implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $callbackId,
        public string $type,
        public ?string $merchantRequestId = null,
        public ?string $checkoutRequestId = null,
        public ?string $amount = null,
        public ?string $receipt = null,
        public ?string $phone = null
    ) {}

    public function handle(MpesaService $mpesaService)
    {
        if ($this->type === 'stk') {
            if ($this->amount && $this->merchantRequestId && $this->receipt && $this->phone) {
                $mpesaService->processStkResult($this->callbackId, $this->merchantRequestId, $this->amount, $this->receipt, $this->phone);
            } elseif ($this->merchantRequestId && !$this->amount) {
                // STK failed or did not provide metadata: mark as failed
                $mpesaService->markStkFailed($this->merchantRequestId, 'STK push failed or user cancelled');
            }
        } elseif ($this->type === 'c2b') {
            if ($this->receipt && $this->amount && $this->phone && $this->checkoutRequestId) {
                // Here checkoutRequestId holds accountRef for convenience
                $mpesaService->processC2bConfirmation($this->callbackId, $this->receipt, $this->amount, $this->phone, $this->checkoutRequestId);
            }
        }
    }
}
