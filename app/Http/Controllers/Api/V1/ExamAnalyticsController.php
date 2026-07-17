<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use App\Models\Exam;
use App\Models\Grade;

class ExamAnalyticsController extends Controller
{
    public function index()
    {
        // 1. Overall Stats
        $stats = [
            'total'     => Exam::count(),
            'upcoming'  => Exam::where('status', 'scheduled')->count(),
            'ongoing'   => Exam::where('status', 'ongoing')->count(),
            'completed' => Exam::where('status', 'completed')->count(),
        ];

        // 2. Grade Distribution (from your grades table)
        $grades = Grade::select('letter_grade as grade', DB::raw('count(*) as count'))
            ->groupBy('letter_grade')
            ->get()
            ->map(function ($item) use ($stats) {
                $totalGrades = Grade::count() ?: 1;
                return [
                    'grade' => $item->grade,
                    'count' => $item->count,
                    'pct'   => round(($item->count / $totalGrades) * 100),
                ];
            });

        // 3. Subject Performance
        $subjects = DB::table('grades')
            ->join('exams', 'grades.exam_id', '=', 'exams.id')
            ->join('subjects', 'exams.subject_id', '=', 'subjects.id')
            ->select(
                'subjects.name',
                DB::raw('ROUND(AVG(grades.percentage), 1) as avg'),
                DB::raw('ROUND(SUM(CASE WHEN grades.status = "pass" THEN 1 ELSE 0 END) / COUNT(*) * 100, 1) as pass')
            )
            ->groupBy('subjects.name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'stats'    => $stats,
                'grades'   => $grades,
                'subjects' => $subjects
            ]
        ]);
    }
}