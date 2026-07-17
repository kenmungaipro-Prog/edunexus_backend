<?php

namespace App\Http\Controllers\Api\Communication;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ParentProfile;
use App\Services\SmsService;
use App\Jobs\SendSmsJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class SmsController extends Controller
{
    protected $sms;

    public function __construct(SmsService $sms)
    {
        $this->sms = $sms;
    }

    public function sendBulk(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'message' => 'required|string|max:1000',
            'parent_ids' => 'nullable|array',
            'parent_ids.*' => 'integer|exists:parent_profiles,id',
            'send_to_all' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $schoolId = Auth::user()->school_id ?? null;

        $query = ParentProfile::query();
        if ($schoolId) {
            $query->where('school_id', $schoolId);
        }

        if ($request->filled('parent_ids')) {
            $query->whereIn('id', $request->input('parent_ids'));
        } elseif (!$request->boolean('send_to_all')) {
            return response()->json(['success' => false, 'message' => 'No recipients specified'], 422);
        }

        $parents = $query->get(['id', 'phone']);
        $results = [];

        // Batching configuration
        $batchSize = (int) config('sms.batch_size', env('SMS_BATCH_SIZE', 50));
        $batchDelay = (int) config('sms.batch_delay', env('SMS_BATCH_DELAY', 1));

        $recipients = $parents->map(fn($p) => ['parent_profile_id' => $p->id, 'phone' => $p->phone])->toArray();

        $chunks = array_chunk($recipients, max(1, $batchSize));

        foreach ($chunks as $i => $chunk) {
            $delaySeconds = $i * $batchDelay;
            // Dispatch batch job for this chunk with incremental delay for rate limiting
            \App\Jobs\SendSmsBatchJob::dispatch($schoolId, $chunk, $request->input('message'))
                ->delay(now()->addSeconds($delaySeconds));

            foreach ($chunk as $c) {
                $results[] = [
                    'parent_profile_id' => $c['parent_profile_id'],
                    'phone' => $c['phone'],
                    'queued' => true,
                    'batch' => $i,
                ];
            }
        }

        return response()->json(['success' => true, 'results' => $results]);
    }
}
