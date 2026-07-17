<?php

namespace App\Jobs;

use App\Services\SmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [10, 60, 300];

    protected $parentProfileId;
    protected $phone;
    protected $message;
    protected $schoolId;

    public function __construct(?int $schoolId, ?int $parentProfileId, ?string $phone, string $message)
    {
        $this->schoolId = $schoolId;
        $this->parentProfileId = $parentProfileId;
        $this->phone = $phone;
        $this->message = $message;
    }

    public function handle(SmsService $sms)
    {
        try {
            $res = $sms->send($this->phone ?? '', $this->message);

            DB::table('sms_logs')->insert([
                'school_id' => $this->schoolId,
                'parent_profile_id' => $this->parentProfileId,
                'phone' => $this->phone,
                'message' => $this->message,
                'status' => ($res['success'] ?? false) ? 'sent' : 'failed',
                'provider_response' => json_encode($res),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('SendSmsJob failed: ' . $e->getMessage(), ['parent' => $this->parentProfileId, 'phone' => $this->phone]);
            // Re-throw so Laravel can retry according to $tries/backoff
            throw $e;
        }
    }

    public function failed(\Throwable $exception)
    {
        // Mark as failed in logs
        DB::table('sms_logs')->insert([
            'school_id' => $this->schoolId,
            'parent_profile_id' => $this->parentProfileId,
            'phone' => $this->phone,
            'message' => $this->message,
            'status' => 'failed',
            'provider_response' => json_encode(['error' => $exception->getMessage()]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
