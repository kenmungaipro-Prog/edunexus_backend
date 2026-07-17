<?php

namespace App\Http\Controllers\Api\Communication;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Jobs\SendSmsJob;

class SmsLogController extends Controller
{
    public function index(Request $request)
    {
        $perPage = (int) $request->get('per_page', 25);
        $query = DB::table('sms_logs')->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->get('status'));
        }

        if ($request->filled('phone')) {
            $query->where('phone', 'like', '%' . $request->get('phone') . '%');
        }

        $p = $query->paginate($perPage);
        return response()->json(['success' => true, 'data' => $p]);
    }

    public function retry($id)
    {
        $log = DB::table('sms_logs')->where('id', $id)->first();
        if (!$log) {
            return response()->json(['success' => false, 'message' => 'Log not found'], 404);
        }

        // Dispatch a job to resend the message
        SendSmsJob::dispatch($log->school_id, $log->parent_profile_id, $log->phone, $log->message);

        return response()->json(['success' => true, 'message' => 'Retry queued']);
    }
}
