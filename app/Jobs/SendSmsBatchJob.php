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

class SendSmsBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [10, 60, 300];

    protected $schoolId;
    protected $recipients; // array of ['parent_profile_id' => int|null, 'phone' => string|null]
    protected $message;

    public function __construct(?int $schoolId, array $recipients, string $message)
    {
        $this->schoolId = $schoolId;
        $this->recipients = $recipients;
        $this->message = $message;
    }

    public function handle(SmsService $sms)
    {
        foreach ($this->recipients as $r) {
            $phone = $r['phone'] ?? null;
            $parentId = $r['parent_profile_id'] ?? null;

            try {
                $res = $sms->send($phone ?? '', $this->message);

                DB::table('sms_logs')->insert([
                    'school_id' => $this->schoolId,
                    'parent_profile_id' => $parentId,
                    'phone' => $phone,
                    'message' => $this->message,
                    'status' => ($res['success'] ?? false) ? 'sent' : 'failed',
                    'provider_response' => json_encode($res),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (\Throwable $e) {
                Log::error('SendSmsBatchJob error: ' . $e->getMessage(), ['parent' => $parentId, 'phone' => $phone]);
                DB::table('sms_logs')->insert([
                    'school_id' => $this->schoolId,
                    'parent_profile_id' => $parentId,
                    'phone' => $phone,
                    'message' => $this->message,
                    'status' => 'failed',
                    'provider_response' => json_encode(['error' => $e->getMessage()]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
