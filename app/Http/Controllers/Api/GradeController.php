<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\{JsonResponse, Request};

class GradeController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'exam_id'                     => 'required|exists:exams,id',
            'grades'                      => 'required|array|min:1',
            'grades.*.student_id'         => 'required|exists:students,id',
            'grades.*.marks_obtained'     => 'required|numeric|min:0',
            'grades.*.remarks'            => 'nullable|string|max:500',
        ]);

        $exam = \App\Models\Exam::findOrFail($request->exam_id);

        if ($request->grades[0]['marks_obtained'] > $exam->total_marks) {
            return response()->json([
                'success' => false,
                'message' => "Marks cannot exceed total marks ({$exam->total_marks}).",
            ], 422);
        }

        $saved = 0;
        \Illuminate\Support\Facades\DB::transaction(function () use ($request, $exam, &$saved) {
            foreach ($request->grades as $entry) {
                $pct    = round(($entry['marks_obtained'] / $exam->total_marks) * 100, 1);
                $letter = $this->letterGrade($pct);

                \App\Models\Grade::updateOrCreate(
                    ['exam_id' => $exam->id, 'student_id' => $entry['student_id']],
                    [
                        'marks_obtained' => $entry['marks_obtained'],
                        'total_marks'    => $exam->total_marks,
                        'percentage'     => $pct,
                        'letter_grade'   => $letter,
                        'status'         => $entry['marks_obtained'] >= $exam->passing_marks ? 'pass' : 'fail',
                        'remarks'        => $entry['remarks'] ?? null,
                        'entered_by'     => auth()->id(),
                    ]
                );
                $saved++;
            }

            $exam->update(['status' => 'completed']);
        });

        return response()->json([
            'success' => true,
            'message' => "Grades saved for {$saved} students.",
        ]);
    }

    public function distribution(Request $request): JsonResponse
    {
        $request->validate(['class_id' => 'required|exists:class_rooms,id']);

        $dist = \App\Models\Grade::whereHas('exam', fn ($q) => $q->where('class_id', $request->class_id))
            ->selectRaw('letter_grade, COUNT(*) as count, ROUND(AVG(percentage),1) as avg_pct')
            ->groupBy('letter_grade')
            ->orderByRaw("FIELD(letter_grade,'A+','A','B+','B','C','D','F')")
            ->get();

        return response()->json(['success' => true, 'data' => $dist]);
    }

    public function subjectPerformance(Request $request): JsonResponse
    {
        $request->validate(['class_id' => 'required|exists:class_rooms,id']);

        $perf = \App\Models\Grade::whereHas('exam', fn ($q) => $q->where('class_id', $request->class_id))
            ->with('exam.subject')
            ->get()
            ->groupBy('exam.subject.name')
            ->map(fn ($grades) => [
                'highest'   => (float) $grades->max('marks_obtained'),
                'average'   => round((float) $grades->avg('marks_obtained'), 1),
                'lowest'    => (float) $grades->min('marks_obtained'),
                'pass_rate' => round($grades->where('status', 'pass')->count() / $grades->count() * 100, 1),
                'count'     => $grades->count(),
            ]);

        return response()->json(['success' => true, 'data' => $perf]);
    }

    private function letterGrade(float $pct): string
    {
        return match (true) {
            $pct >= 90 => 'A+',
            $pct >= 80 => 'A',
            $pct >= 70 => 'B+',
            $pct >= 60 => 'B',
            $pct >= 50 => 'C',
            $pct >= 35 => 'D',
            default    => 'F',
        };
    }
}
