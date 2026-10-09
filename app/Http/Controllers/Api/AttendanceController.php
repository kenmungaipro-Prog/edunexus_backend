<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\ClassRoom;
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
        $validated = $request->validate([
            'class_id'  => 'sometimes|nullable|integer|exists:class_rooms,id',
            'date_from' => 'sometimes|nullable|date_format:Y-m-d',
            'date_to'   => 'sometimes|nullable|date_format:Y-m-d|after_or_equal:date_from',
            'date'      => 'sometimes|nullable|date_format:Y-m-d',
            'month'     => 'sometimes|nullable|integer|between:1,12',
            'year'      => 'sometimes|nullable|integer|between:2000,2100',
            'status'    => 'sometimes|nullable|in:present,absent,late,holiday,excused',
            'per_page'  => 'sometimes|nullable|integer|between:1,1000',
        ]);

        $attendance = Attendance::with(['student', 'classRoom', 'markedBy'])
            ->where(fn ($q) => $q->whereHas('student', fn ($s) => $s->where('school_id', currentSchoolId())))
            ->when($validated['class_id'] ?? null, fn ($q, $v) => $q->where('class_id', $v))
            ->when($validated['date'] ?? null, fn ($q, $v) => $q->whereDate('date', $v))
            ->when($validated['month'] ?? null, fn ($q, $v) => $q->whereMonth('date', $v))
            ->when($validated['year'] ?? null, fn ($q, $v) => $q->whereYear('date', $v))
            ->when($validated['date_from'] ?? null, fn ($q, $v) => $q->whereDate('date', '>=', $v))
            ->when($validated['date_to'] ?? null, fn ($q, $v) => $q->whereDate('date', '<=', $v))
            ->when($validated['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('date')
            ->paginate($validated['per_page'] ?? 50);

        return response()->json(['success' => true, 'data' => $attendance]);
    }

    public function report(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'class_id'  => 'required|integer|exists:class_rooms,id',
            'date_from' => 'required|date_format:Y-m-d',
            'date_to'   => 'required|date_format:Y-m-d|after_or_equal:date_from',
        ]);

        $class = ClassRoom::query()
            ->where('school_id', currentSchoolId())
            ->findOrFail($validated['class_id']);

        $baseQuery = Attendance::query()
            ->where('class_id', $class->id)
            ->whereBetween('date', [$validated['date_from'], $validated['date_to']])
            ->whereHas('student', fn ($query) => $query->where('school_id', currentSchoolId()));

        $countsByStudent = (clone $baseQuery)
            ->selectRaw('student_id, status, COUNT(*) as total')
            ->groupBy('student_id', 'status')
            ->get()
            ->groupBy('student_id');

        $countsByDate = (clone $baseQuery)
            ->selectRaw('date, status, COUNT(*) as total')
            ->groupBy('date', 'status')
            ->orderBy('date')
            ->get()
            ->groupBy(fn ($row) => substr((string) $row->date, 0, 10));

        $attendanceStudentIds = $countsByStudent->keys()->all();
        $students = Student::withTrashed()
            ->where('school_id', currentSchoolId())
            ->where(function ($query) use ($class, $attendanceStudentIds) {
                $query->where('class_id', $class->id);
                if ($attendanceStudentIds !== []) {
                    $query->orWhereIn('id', $attendanceStudentIds);
                }
            })
            ->orderBy('roll_number')
            ->get(['id', 'roll_number', 'first_name', 'last_name'])
            ->map(function (Student $student) use ($countsByStudent) {
                $counts = [
                    'present' => 0,
                    'absent' => 0,
                    'late' => 0,
                    'holiday' => 0,
                    'excused' => 0,
                ];

                foreach ($countsByStudent->get($student->id, collect()) as $row) {
                    $counts[$row->status] = (int) $row->total;
                }

                $recordedDays = $counts['present'] + $counts['absent'] + $counts['late'];

                return [
                    'id' => $student->id,
                    'roll_number' => $student->roll_number,
                    'name' => $student->full_name,
                    ...$counts,
                    'recorded_days' => $recordedDays,
                    'attendance_rate' => $recordedDays > 0
                        ? round((($counts['present'] + $counts['late']) / $recordedDays) * 100, 1)
                        : null,
                ];
            })
            ->values();

        $daily = $countsByDate->map(function ($rows, $date) {
            $counts = [
                'present' => 0,
                'absent' => 0,
                'late' => 0,
                'holiday' => 0,
                'excused' => 0,
            ];

            foreach ($rows as $row) {
                $counts[$row->status] = (int) $row->total;
            }

            $recordedDays = $counts['present'] + $counts['absent'] + $counts['late'];

            return [
                'date' => $date,
                ...$counts,
                'records_count' => array_sum($counts),
                'attendance_rate' => $recordedDays > 0
                    ? round((($counts['present'] + $counts['late']) / $recordedDays) * 100, 1)
                    : null,
            ];
        })->values();

        $countsByStatus = (clone $baseQuery)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $totals = [
            'present' => (int) ($countsByStatus->get('present')->total ?? 0),
            'absent' => (int) ($countsByStatus->get('absent')->total ?? 0),
            'late' => (int) ($countsByStatus->get('late')->total ?? 0),
            'holiday' => (int) ($countsByStatus->get('holiday')->total ?? 0),
            'excused' => (int) ($countsByStatus->get('excused')->total ?? 0),
        ];
        $recordedDays = $totals['present'] + $totals['absent'] + $totals['late'];

        return response()->json([
            'success' => true,
            'data' => [
                'class' => ['id' => $class->id, 'name' => $class->name],
                'date_from' => $validated['date_from'],
                'date_to' => $validated['date_to'],
                'summary' => [
                    ...$totals,
                    'recorded_days' => $recordedDays,
                    'students_count' => $students->count(),
                    'attendance_rate' => $recordedDays > 0
                        ? round((($totals['present'] + $totals['late']) / $recordedDays) * 100, 1)
                        : null,
                ],
                'students' => $students,
                'daily' => $daily,
            ],
        ]);
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
