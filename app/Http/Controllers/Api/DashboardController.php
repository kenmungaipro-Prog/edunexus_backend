<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Book;
use App\Models\BookIssue;
use App\Models\ClassRoom;
use App\Models\Event;
use App\Models\Fee;
use App\Models\Grade;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function stats(): JsonResponse
    {
        $schoolId = currentSchoolId();

        $teacher = Teacher::where('school_id', $schoolId)
            ->where('user_id', auth()->id())
            ->first();
        $teacherClassRows = $teacher
            ? ClassRoom::where('school_id', $schoolId)
                ->where('class_teacher_id', $teacher->id)
                ->withCount('activeEnrolledStudents')
                ->get()
            : collect();
        $teacherClassIds = $teacherClassRows->pluck('id')->all();

        $classDistribution = ClassRoom::where('school_id', $schoolId)
            ->withCount('activeEnrolledStudents')
            ->get()
            ->groupBy('grade')
            ->map(fn ($classes, $grade) => [
                'grade'    => "Grade {$grade}",
                'students' => (int) $classes->sum('active_enrolled_students_count'),
                'classes'  => $classes->count(),
            ])
            ->values();

        $topPerformers = Grade::query()
            ->selectRaw('student_id, ROUND(AVG(percentage), 1) as average_percentage')
            ->whereHas('student', function ($query) use ($schoolId, $teacher, $teacherClassIds) {
                $query->where('school_id', $schoolId)->where('status', 'active');
                if ($teacher) {
                    $query->whereIn('class_id', $teacherClassIds);
                }
            })
            ->with('student.classRoom')
            ->groupBy('student_id')
            ->orderByDesc('average_percentage')
            ->limit(5)
            ->get()
            ->map(fn ($grade) => [
                'id'         => $grade->student_id,
                'name'       => $grade->student->full_name,
                'grade_name' => $grade->student->classRoom?->name,
                'percentage' => (float) $grade->average_percentage,
            ]);

        $stats = [
            'students'          => Student::where('school_id', $schoolId)->where('status', 'active')->count(),
            'teachers'          => Teacher::where('school_id', $schoolId)->where('status', 'active')->count(),
            'fee_collected'     => (float) Fee::whereHas('student', fn ($q) => $q->where('school_id', $schoolId))
                ->where('status', 'paid')
                ->whereYear('paid_at', now()->year)
                ->sum('amount'),
            'avg_attendance'    => $this->avgAttendance($schoolId, $teacher ? $teacherClassIds : null),
            'upcoming_events'   => Event::where('school_id', $schoolId)
                ->whereDate('event_date', '>=', today())
                ->count(),
            'pending_fees'      => Fee::whereHas('student', fn ($q) => $q->where('school_id', $schoolId))
                ->where('status', 'pending')
                ->count(),
            'low_attendance'    => $this->lowAttendanceCount($schoolId),
            'classes_count'     => $teacherClassRows->count(),
            'my_students_count' => (int) $teacherClassRows->sum('active_enrolled_students_count'),
            'total_books'       => Book::count(),
            'borrowed_books'    => BookIssue::where('status', 'issued')->count(),
            'class_distribution'=> $classDistribution,
            'top_performers'    => $topPerformers,
        ];

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
            return [now()->month($m)->format('M') => $this->attendanceRate($schoolId, $m, now()->year)];
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

    private function avgAttendance(int $schoolId, ?array $classIds = null): ?float
    {
        return $this->attendanceRate($schoolId, now()->month, now()->year, $classIds);
    }

    private function attendanceRate(int $schoolId, int $month, int $year, ?array $classIds = null): ?float
    {
        $counts = Attendance::whereHas('student', function ($query) use ($schoolId, $classIds) {
            $query->where('school_id', $schoolId);
            if ($classIds !== null) {
                $query->whereIn('class_id', $classIds);
            }
        })
            ->whereMonth('date', $month)
            ->whereYear('date', $year)
            ->whereIn('status', ['present', 'late', 'absent'])
            ->selectRaw("COUNT(*) as total, SUM(CASE WHEN status IN ('present', 'late') THEN 1 ELSE 0 END) as attended")
            ->first();

        return $counts->total > 0
            ? round(((int) $counts->attended / (int) $counts->total) * 100, 1)
            : null;
    }

    private function lowAttendanceCount(int $schoolId): int
    {
        return Attendance::whereHas('student', fn ($query) => $query
            ->where('school_id', $schoolId)
            ->where('status', 'active'))
            ->whereMonth('date', now()->month)
            ->whereYear('date', now()->year)
            ->whereIn('status', ['present', 'late', 'absent'])
            ->select('student_id')
            ->groupBy('student_id')
            ->havingRaw("SUM(CASE WHEN status IN ('present', 'late') THEN 1 ELSE 0 END) / COUNT(*) < ?", [0.8])
            ->get()
            ->count();
    }
}