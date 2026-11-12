<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Attendance, ClassRoom, Exam, Fee, FeeType, Grade};
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    public function overview(): JsonResponse
    {
        $schoolId = currentSchoolId();

        $avgAttendance = $this->getAttendanceRate($schoolId, now()->month, now()->year);
        $passRate = $this->getPassRate($schoolId);
        $feeCollection = $this->getFeeCollectionRate($schoolId);

        return response()->json([
            'success' => true,
            'data'    => [
                'avg_attendance'  => $avgAttendance,
                'pass_rate'       => $passRate,
                'fee_collection'  => $feeCollection,
            ],
        ]);
    }

    public function gradePerformance(): JsonResponse
    {
        $schoolId = currentSchoolId();

        $data = ClassRoom::where('school_id', $schoolId)
            ->withCount('activeEnrolledStudents as students_count')
            ->withAvg(['grades' => fn ($query) => $query->whereHas('student', fn ($studentQuery) => $studentQuery
                ->where('school_id', $schoolId)
                ->where('status', 'active'))], 'percentage')
            ->get()
            ->map(fn($c) => [
                'class'    => $c->name,
                'students' => $c->students_count,
                'avg'      => $c->grades_avg_percentage !== null
                    ? round((float) $c->grades_avg_percentage, 1)
                    : null,
            ]);

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function attendanceTrend(): JsonResponse
    {
        $schoolId = currentSchoolId();

        $trend = collect(range(1, 12))->map(fn($m) => [
            'month' => now()->month($m)->format('M'),
            'rate'  => $this->getAttendanceRate($schoolId, $m, now()->year),
        ]);

        return response()->json(['success' => true, 'data' => $trend]);
    }

    public function feeAnalytics(): JsonResponse
    {
        $schoolId = currentSchoolId();
        $year = now()->year;

        $feeTypes = FeeType::where(fn ($query) => $query
            ->where('school_id', $schoolId)
            ->orWhereNull('school_id'))
            ->get();

        $collectedByType = DB::table('fees')
            ->join('students', 'fees.student_id', '=', 'students.id')
            ->where('students.school_id', $schoolId)
            ->where('fees.status', 'paid')
            ->whereYear('fees.paid_at', $year)
            ->select('fees.fee_type_id', DB::raw('SUM(fees.amount) as collected'))
            ->groupBy('fees.fee_type_id')
            ->pluck('collected', 'fee_type_id');

        $byType = $feeTypes->map(fn ($feeType) => [
            'name'      => $feeType->name,
            'collected' => (float) ($collectedByType[$feeType->id] ?? 0),
        ]);

        $byMonth = collect(range(1, 12))->map(fn ($m) => [
            'month'     => now()->month($m)->format('M'),
            'collected' => Fee::whereHas('student', fn($q) => $q->where('school_id', $schoolId))
                ->whereMonth('paid_at', $m)
                ->whereYear('paid_at', $year)
                ->where('status', 'paid')
                ->sum('amount'),
        ]);

        $totals = [
            'paid'    => (float) Fee::whereHas('student', fn($q) => $q->where('school_id', $schoolId))
                ->where('status', 'paid')
                ->whereYear('paid_at', $year)
                ->sum('amount'),
            'pending' => (float) Fee::whereHas('student', fn($q) => $q->where('school_id', $schoolId))
                ->where('status', 'pending')
                ->whereYear('created_at', $year)
                ->sum('amount'),
            'overdue' => (float) Fee::whereHas('student', fn($q) => $q->where('school_id', $schoolId))
                ->where('status', 'overdue')
                ->whereYear('created_at', $year)
                ->sum('amount'),
        ];

        return response()->json([
            'success' => true,
            'data'    => [
                'by_type'  => $byType,
                'by_month' => $byMonth,
                'totals'   => $totals,
            ],
        ]);
    }

    public function examPerformance(): JsonResponse
    {
        $schoolId = currentSchoolId();

        $stats = [
            'total'     => Exam::whereHas('classRoom', fn($q) => $q->where('school_id', $schoolId))->count(),
            'upcoming'  => Exam::whereHas('classRoom', fn($q) => $q->where('school_id', $schoolId))
                ->where('status', 'scheduled')
                ->whereDate('exam_date', '>=', today())
                ->count(),
            'ongoing'   => Exam::whereHas('classRoom', fn($q) => $q->where('school_id', $schoolId))->where('status', 'ongoing')->count(),
            'completed' => Exam::whereHas('classRoom', fn($q) => $q->where('school_id', $schoolId))->where('status', 'completed')->count(),
        ];

        $schoolGrades = Grade::whereHas('student', fn($q) => $q->where('school_id', $schoolId));
        $gradeTotal = (clone $schoolGrades)->count();
        $grades = (clone $schoolGrades)
            ->select('letter_grade as grade', DB::raw('count(*) as count'))
            ->groupBy('letter_grade')
            ->orderBy('letter_grade')
            ->get()
            ->map(function ($item) use ($gradeTotal) {
                return [
                    'grade' => $item->grade,
                    'count' => (int)$item->count,
                    'pct'   => $gradeTotal > 0 ? round(($item->count / $gradeTotal) * 100) : 0,
                    'color' => match($item->grade) {
                        'A+' => 'bg-emerald-400',
                        'A'  => 'bg-blue-400',
                        'B'  => 'bg-amber-400',
                        'C'  => 'bg-orange-400',
                        default => 'bg-red-400',
                    },
                ];
            });

        $subjects = DB::table('grades')
            ->join('exams', 'grades.exam_id', '=', 'exams.id')
            ->join('subjects', 'exams.subject_id', '=', 'subjects.id')
            ->join('class_rooms', 'exams.class_id', '=', 'class_rooms.id')
            ->where('class_rooms.school_id', $schoolId)
            ->select(
                'subjects.id',
                'subjects.name',
                DB::raw('ROUND(AVG(grades.percentage), 1) as avg'),
                DB::raw('ROUND(SUM(CASE WHEN grades.status = "pass" THEN 1 ELSE 0 END) / COUNT(*) * 100, 1) as pass')
            )
            ->groupBy('subjects.id', 'subjects.name')
            ->get()
            ->map(fn ($subject) => [
                'name' => $subject->name,
                'avg'  => (float) $subject->avg,
                'pass' => (float) $subject->pass,
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'stats'    => $stats,
                'grades'   => $grades,
                'subjects' => $subjects,
            ],
        ]);
    }

    private function getAttendanceRate(int $schoolId, int $month, int $year): ?float
    {
        $counts = Attendance::whereHas('student', fn ($q) => $q->where('school_id', $schoolId))
            ->whereMonth('date', $month)
            ->whereYear('date', $year)
            ->whereIn('status', ['present', 'late', 'absent'])
            ->selectRaw("COUNT(*) as total, SUM(CASE WHEN status IN ('present', 'late') THEN 1 ELSE 0 END) as attended")
            ->first();

        return $counts->total > 0
            ? round(((int) $counts->attended / (int) $counts->total) * 100, 1)
            : null;
    }

    private function getPassRate(int $schoolId): ?float
    {
        $total = Grade::whereHas('student', fn ($q) => $q->where('school_id', $schoolId))->count();
        $passed = Grade::whereHas('student', fn ($q) => $q->where('school_id', $schoolId))
            ->where('status', 'pass')
            ->count();

        return $total > 0 ? round(($passed / $total) * 100, 1) : null;
    }

    private function getFeeCollectionRate(int $schoolId): ?float
    {
        $assessed = Fee::whereHas('student', fn ($q) => $q->where('school_id', $schoolId))
            ->whereIn('status', ['paid', 'partial', 'pending'])
            ->whereYear('created_at', now()->year)
            ->sum('amount');
        $collected = Fee::whereHas('student', fn ($q) => $q->where('school_id', $schoolId))
            ->where('status', 'paid')
            ->whereYear('paid_at', now()->year)
            ->sum('amount');

        return $assessed > 0 ? round(($collected / $assessed) * 100, 1) : null;
    }
}