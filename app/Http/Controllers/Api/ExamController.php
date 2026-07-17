<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\Grade;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExamController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $exams = Exam::with(['classRoom', 'subject', 'invigilator.user'])
            ->when($request->class_id,  fn($q, $v) => $q->where('class_id', $v))
            ->when($request->status,    fn($q, $v) => $q->where('status', $v))
            ->when($request->upcoming,  fn($q)     => $q->where('exam_date', '>=', now()))
            ->orderBy('exam_date')
            ->paginate($request->per_page ?? 20);

        return response()->json(['success' => true, 'data' => $exams]);
    }

    public function store(Request $request): JsonResponse
    {
        // 1. Capture the validated data into a variable
        $validated = $request->validate([
            'title'          => 'required|string|max:255',
            'class_id'       => 'required|exists:class_rooms,id',
            'subject_id'     => 'required|exists:subjects,id',
            'exam_date'      => 'required|date|after:today',
            'start_time'     => 'required|date_format:H:i',
            'end_time'       => 'required|date_format:H:i|after:start_time',
            'total_marks'    => 'required|integer|min:1',
            'passing_marks'  => 'required|integer|lt:total_marks',
            'room'           => 'nullable|string',
            'invigilator_id' => 'nullable|exists:teachers,id',
            'instructions'   => 'nullable|string',
        ]);

        // 2. Spread the $validated array instead of calling $request->validated()
        $exam = Exam::create([
            ...$validated,
            'session_id' => currentSession(),
            'created_by' => auth()->id(),
            'status'     => 'scheduled',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Exam scheduled successfully.',
            'data'    => $exam->load('classRoom', 'subject'),
        ], 201);
    }

    public function show(Exam $exam): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $exam->load(['classRoom.students', 'subject', 'invigilator.user', 'grades.student']),
        ]);
    }

    public function update(Request $request, Exam $exam): JsonResponse
    {
        // 1. Capture the validated array
        $validated = $request->validate([
            'title'         => 'sometimes|string|max:255',
            'exam_date'     => 'sometimes|date',
            'start_time'    => 'sometimes|date_format:H:i',
            'end_time'      => 'sometimes|date_format:H:i',
            'total_marks'   => 'sometimes|integer|min:1',
            'passing_marks' => 'sometimes|integer',
            'room'          => 'nullable|string',
            'status'        => 'sometimes|in:scheduled,ongoing,completed,cancelled',
        ]);

        // 2. Pass the array directly to update()
        $exam->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Exam updated.',
            'data'    => $exam->fresh(['classRoom', 'subject']),
        ]);
    }

    public function destroy(Exam $exam): JsonResponse
    {
        if ($exam->grades()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete exam with recorded grades.',
            ], 422);
        }

        $exam->delete();

        return response()->json(['success' => true, 'message' => 'Exam deleted.']);
    }
}

class GradeController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'exam_id' => 'required|exists:exams,id',
            'grades'  => 'required|array|min:1',
            'grades.*.student_id'     => 'required|exists:students,id',
            'grades.*.marks_obtained' => 'required|numeric|min:0',
            'grades.*.remarks'        => 'nullable|string',
        ]);

        $exam = Exam::findOrFail($request->exam_id);

        DB::transaction(function () use ($request, $exam) {
            foreach ($request->grades as $entry) {
                $percentage = round(($entry['marks_obtained'] / $exam->total_marks) * 100, 1);
                $letterGrade = $this->calculateLetterGrade($percentage);

                Grade::updateOrCreate(
                    ['exam_id' => $exam->id, 'student_id' => $entry['student_id']],
                    [
                        'marks_obtained' => $entry['marks_obtained'],
                        'total_marks'    => $exam->total_marks,
                        'percentage'     => $percentage,
                        'letter_grade'   => $letterGrade,
                        'status'         => $entry['marks_obtained'] >= $exam->passing_marks ? 'pass' : 'fail',
                        'remarks'        => $entry['remarks'] ?? null,
                        'entered_by'     => auth()->id(),
                    ]
                );
            }

            $exam->update(['status' => 'completed']);
        });

        return response()->json([
            'success' => true,
            'message' => 'Grades saved for ' . count($request->grades) . ' students.',
        ]);
    }

    public function distribution(Request $request): JsonResponse
    {
        $request->validate(['class_id' => 'required|exists:class_rooms,id']);

        $distribution = Grade::whereHas('exam', fn($q) => $q->where('class_id', $request->class_id))
            ->selectRaw("letter_grade, COUNT(*) as count")
            ->groupBy('letter_grade')
            ->orderByRaw("FIELD(letter_grade, 'A+', 'A', 'B+', 'B', 'C', 'D', 'F')")
            ->get();

        return response()->json(['success' => true, 'data' => $distribution]);
    }

    public function subjectPerformance(Request $request): JsonResponse
    {
        $request->validate(['class_id' => 'required|exists:class_rooms,id']);

        $performance = Grade::whereHas('exam', fn($q) => $q->where('class_id', $request->class_id))
            ->with('exam.subject')
            ->get()
            ->groupBy('exam.subject.name')
            ->map(fn($grades) => [
                'highest' => $grades->max('marks_obtained'),
                'average' => round($grades->avg('marks_obtained'), 1),
                'lowest'  => $grades->min('marks_obtained'),
                'pass_rate' => round($grades->where('status', 'pass')->count() / $grades->count() * 100, 1),
            ]);

        return response()->json(['success' => true, 'data' => $performance]);
    }

    private function calculateLetterGrade(float $percentage): string
    {
        return match (true) {
            $percentage >= 90 => 'A+',
            $percentage >= 80 => 'A',
            $percentage >= 70 => 'B+',
            $percentage >= 60 => 'B',
            $percentage >= 50 => 'C',
            $percentage >= 35 => 'D',
            default           => 'F',
        };
    }
}
