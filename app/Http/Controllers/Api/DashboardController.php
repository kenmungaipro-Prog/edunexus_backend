<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\Fee;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    public function stats(): JsonResponse
    {
        $schoolId = currentSchoolId();

        $stats = Cache::remember("dashboard.stats.{$schoolId}", 300, function () use ($schoolId) {
            $totalStudents  = Student::where('school_id', $schoolId)->where('status', 'active')->count();
            $totalTeachers  = Teacher::where('school_id', $schoolId)->where('status', 'active')->count();
            $feeCollected   = (float) Fee::whereHas('student', fn ($q) => $q->where('school_id', $schoolId))
                ->where('status', 'paid')
                ->whereYear('paid_at', now()->year)
                ->sum('amount');
            $avgAttendance  = $this->avgAttendance($schoolId);
            $upcomingEvents = Event::where('school_id', $schoolId)
                ->where('event_date', '>=', now())
                ->count();
            $pendingFees    = Fee::whereHas('student', fn ($q) => $q->where('school_id', $schoolId))
                ->where('status', 'pending')
                ->count();
            $lowAttendance  = Student::where('school_id', $schoolId)
                ->where('status', 'active')
                ->get()
                ->filter(fn ($s) => $s->attendance_percentage < 80)
                ->count();

            return [
                'students'        => $totalStudents,
                'teachers'        => $totalTeachers,
                'fee_collected'   => $feeCollected,
                'avg_attendance'  => $avgAttendance,
                'upcoming_events' => $upcomingEvents,
                'pending_fees'    => $pendingFees,
                'low_attendance'  => $lowAttendance,
            ];
        });

        return response()->json(['success' => true, 'data' => $stats]);
    }

    public function activity(): JsonResponse
    {
        $schoolId = currentSchoolId();

        $feeActivity = Fee::with('student')
            ->whereHas('student', fn ($q) => $q->where('school_id', $schoolId))
            ->latest()
            ->take(5)
            ->get()
            ->map(fn ($f) => [
                'type' => 'fee',
                'icon' => '💰',
                'text' => "Fee collected from {$f->student->full_name}",
                'time' => $f->created_at->diffForHumans(),
                'ts'   => $f->created_at->timestamp,
            ]);

        $studentActivity = Student::where('school_id', $schoolId)
            ->latest()
            ->take(3)
            ->get()
            ->map(fn ($s) => [
                'type' => 'student',
                'icon' => '🎓',
                'text' => "New student enrolled: {$s->full_name}",
                'time' => $s->created_at->diffForHumans(),
                'ts'   => $s->created_at->timestamp,
            ]);

        $attendanceActivity = Attendance::with('classRoom')
            ->whereHas('student', fn ($q) => $q->where('school_id', $schoolId))
            ->latest()
            ->take(4)
            ->get()
            ->map(fn ($a) => [
                'type' => 'attendance',
                'icon' => '✅',
                'text' => "Attendance marked — {$a->classRoom->name}",
                'time' => $a->created_at->diffForHumans(),
                'ts'   => $a->created_at->timestamp,
            ]);

        $eventActivity = Event::where('school_id', $schoolId)
            ->latest()
            ->take(3)
            ->get()
            ->map(fn ($e) => [
                'type' => 'event',
                'icon' => '🎉',
                'text' => "Event scheduled: {$e->title}",
                'time' => $e->created_at->diffForHumans(),
                'ts'   => $e->created_at->timestamp,
            ]);

        $activity = $feeActivity
            ->concat($studentActivity)
            ->concat($attendanceActivity)
            ->concat($eventActivity)
            ->sortByDesc('ts')
            ->take(15)
            ->values();

        return response()->json(['success' => true, 'data' => $activity]);
    }

    public function charts(): JsonResponse
    {
        $schoolId = currentSchoolId();

        $enrollment = collect(range(1, 12))->mapWithKeys(fn ($m) => [
            now()->month($m)->format('M') => Student::where('school_id', $schoolId)
                ->whereMonth('created_at', $m)
                ->whereYear('created_at', now()->year)
                ->count(),
        ]);

        $feeChart = collect(range(1, 12))->mapWithKeys(fn ($m) => [
            now()->month($m)->format('M') => (float) Fee::whereHas('student', fn ($q) => $q->where('school_id', $schoolId))
                ->whereMonth('paid_at', $m)
                ->whereYear('paid_at', now()->year)
                ->where('status', 'paid')
                ->sum('amount'),
        ]);

        $attendanceChart = collect(range(1, 12))->mapWithKeys(function ($m) use ($schoolId) {
            $total = Attendance::whereHas('student', fn ($q) => $q->where('school_id', $schoolId))
                ->whereMonth('date', $m)
                ->whereYear('date', now()->year)
                ->count();

            $present = Attendance::whereHas('student', fn ($q) => $q->where('school_id', $schoolId))
                ->whereMonth('date', $m)
                ->whereYear('date', now()->year)
                ->where('status', 'present')
                ->count();

            return [
                now()->month($m)->format('M') => $total > 0
                    ? round(($present / $total) * 100, 1)
                    : 0,
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => [
                'enrollment' => $enrollment,
                'fees'       => $feeChart,
                'attendance' => $attendanceChart,
            ],
        ]);
    }

    private function avgAttendance(int $schoolId): float
    {
        $total = Attendance::whereHas('student', fn ($q) => $q->where('school_id', $schoolId))
            ->whereMonth('date', now()->month)
            ->whereYear('date', now()->year)
            ->count();

        $present = Attendance::whereHas('student', fn ($q) => $q->where('school_id', $schoolId))
            ->whereMonth('date', now()->month)
            ->whereYear('date', now()->year)
            ->where('status', 'present')
            ->count();

        return $total > 0 ? round(($present / $total) * 100, 1) : 0.0;
    }
}