<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Student;
use App\Exports\AttendanceExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class AttendanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $attendance = Attendance::with(['student', 'classRoom', 'markedBy'])
            ->where(fn ($q) => $q->whereHas('student', fn ($s) => $s->where('school_id', currentSchoolId())))
            ->when($request->class_id, fn ($q, $v) => $q->where('class_id', $v))
            ->when($request->date,     fn ($q, $v) => $q->whereDate('date', $v))
            ->when($request->month,    fn ($q, $v) => $q->whereMonth('date', $v))
            ->when($request->status,   fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('date')
            ->paginate($request->per_page ?? 50);

        return response()->json(['success' => true, 'data' => $attendance]);
    }

    public function mark(Request $request): JsonResponse
    {
        $request->validate([
            'class_id'              => 'required|exists:class_rooms,id',
            'date'                  => 'required|date|before_or_equal:today',
            'attendance'            => 'required|array|min:1',
            'attendance.*.student_id' => 'required|exists:students,id',
            'attendance.*.status'   => 'required|in:present,absent,late,holiday,excused',
            'attendance.*.remarks'  => 'nullable|string|max:500',
        ]);

        $saved = 0;
        DB::transaction(function () use ($request, &$saved) {
            foreach ($request->attendance as $record) {
                Attendance::updateOrCreate(
                    [
                        'student_id' => $record['student_id'],
                        'date'       => $request->date,
                    ],
                    [
                        'class_id'   => $request->class_id,
                        'status'     => $record['status'],
                        'marked_by'  => auth()->id(),
                        'remarks'    => $record['remarks'] ?? null,
                    ]
                );
                $saved++;
            }
        });

        return response()->json([
            'success' => true,
            'message' => "Attendance marked for {$saved} students on {$request->date}.",
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'status'  => 'required|in:present,absent,late,holiday,excused',
            'remarks' => 'nullable|string|max:500',
        ]);

        $record = Attendance::findOrFail($id);
        $record->update([
            'status'    => $request->status,
            'remarks'   => $request->remarks,
            'marked_by' => auth()->id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Attendance record updated.',
            'data'    => $record->fresh('student'),
        ]);
    }

    public function stats(): JsonResponse
    {
        $today = now()->toDateString();

        $todayRecords = Attendance::whereDate('date', $today)
            ->whereHas('student', fn ($q) => $q->where('school_id', currentSchoolId()))
            ->get();

        return response()->json([
            'success' => true,
            'data'    => [
                'present_today'  => $todayRecords->where('status', 'present')->count(),
                'absent_today'   => $todayRecords->where('status', 'absent')->count(),
                'late_today'     => $todayRecords->where('status', 'late')->count(),
                'overall_rate'   => $this->calculateOverallRate(),
                'weekly_trend'   => $this->weeklyTrend(),
                'class_wise'     => $this->classWiseStats($today),
            ],
        ]);
    }

    public function lowAttendance(Request $request): JsonResponse
    {
        $threshold = $request->threshold ?? 80;

        $low = Student::with('classRoom')
            ->where('school_id', currentSchoolId())
            ->where('status', 'active')
            ->get()
            ->filter(fn ($s) => $s->attendance_percentage < $threshold)
            ->map(fn ($s) => [
                'id'         => $s->id,
                'name'       => $s->full_name,
                'class'      => $s->classRoom->name,
                'percentage' => $s->attendance_percentage,
                'days_absent'=> $s->attendance()->where('status', 'absent')->count(),
            ])
            ->sortBy('percentage')
            ->values();

        return response()->json(['success' => true, 'data' => $low]);
    }

    public function export(Request $request)
    {
        $request->validate([
            'class_id' => 'required|exists:class_rooms,id',
            'month'    => 'required|integer|between:1,12',
            'year'     => 'required|integer',
        ]);

        return Excel::download(
            new AttendanceExport($request->class_id, $request->month, $request->year),
            "attendance-{$request->year}-{$request->month}.xlsx"
        );
    }

    private function calculateOverallRate(): float
    {
        $total = Attendance::whereMonth('date', now()->month)
            ->whereHas('student', fn ($q) => $q->where('school_id', currentSchoolId()))
            ->count();

        $present = Attendance::whereMonth('date', now()->month)
            ->whereHas('student', fn ($q) => $q->where('school_id', currentSchoolId()))
            ->where('status', 'present')
            ->count();

        return $total > 0 ? round(($present / $total) * 100, 1) : 0.0;
    }

    private function weeklyTrend(): array
    {
        return collect(range(6, 0))->map(function ($daysAgo) {
            $date    = now()->subDays($daysAgo);
            $total   = Attendance::whereDate('date', $date)->count();
            $present = Attendance::whereDate('date', $date)->where('status', 'present')->count();
            return [
                'date' => $date->format('Y-m-d'),
                'day'  => $date->format('D'),
                'rate' => $total > 0 ? round(($present / $total) * 100, 1) : 0,
            ];
        })->toArray();
    }

    private function classWiseStats(string $date): array
    {
        return Attendance::whereDate('date', $date)
            ->selectRaw('class_id, status, COUNT(*) as count')
            ->groupBy('class_id', 'status')
            ->with('classRoom:id,name')
            ->get()
            ->groupBy('class_id')
            ->map(fn ($recs) => [
                'class'   => $recs->first()->classRoom->name ?? '—',
                'present' => $recs->where('status', 'present')->sum('count'),
                'absent'  => $recs->where('status', 'absent')->sum('count'),
                'late'    => $recs->where('status', 'late')->sum('count'),
            ])
            ->values()
            ->toArray();
    }
}
