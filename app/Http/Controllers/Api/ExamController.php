<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\Grade;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

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
            'invigilator_id' => ['nullable', 'integer', Rule::exists('teachers', 'id')->where('school_id', currentSchoolId())],
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
        abort_unless(
            $exam->classRoom()->where('school_id', currentSchoolId())->exists(),
            404
        );

        return response()->json([
            'success' => true,
            'data'    => $exam->load(['classRoom.students', 'subject', 'invigilator.user', 'grades.student']),
        ]);
    }

    public function update(Request $request, Exam $exam): JsonResponse
    {
        abort_unless(
            $exam->classRoom()->where('school_id', currentSchoolId())->exists(),
            404
        );

        $validated = $request->validate([
            'title'          => 'sometimes|required|string|max:255',
            'class_id'       => ['sometimes', 'required', 'integer', Rule::exists('class_rooms', 'id')->where('school_id', currentSchoolId())],
            'subject_id'     => ['sometimes', 'required', 'integer', Rule::exists('subjects', 'id')->where('school_id', currentSchoolId())],
            'exam_date'      => 'sometimes|required|date',
            'start_time'     => 'sometimes|required|date_format:H:i',
            'end_time'       => 'sometimes|required|date_format:H:i',
            'total_marks'    => 'sometimes|required|integer|min:1',
            'passing_marks'  => 'sometimes|required|integer|min:0',
            'room'           => 'nullable|string|max:255',
            'invigilator_id' => ['nullable', 'integer', Rule::exists('teachers', 'id')->where('school_id', currentSchoolId())],
            'instructions'   => 'nullable|string|max:5000',
            'status'         => 'sometimes|required|in:scheduled,ongoing,completed,cancelled',
        ]);

        $totalMarks = $validated['total_marks'] ?? $exam->total_marks;
        $passingMarks = $validated['passing_marks'] ?? $exam->passing_marks;
        $startTime = $validated['start_time'] ?? $exam->start_time;
        $endTime = $validated['end_time'] ?? $exam->end_time;
        if (substr((string) $endTime, 0, 5) <= substr((string) $startTime, 0, 5)) {
            throw ValidationException::withMessages([
                'end_time' => ['The end time must be later than the start time.'],
            ]);
        }
        if ($passingMarks >= $totalMarks) {
            throw ValidationException::withMessages([
                'passing_marks' => ['Passing marks must be less than total marks.'],
            ]);
        }

        if ($exam->grades()->exists()) {
            foreach (['class_id', 'subject_id', 'total_marks', 'passing_marks'] as $field) {
                if (array_key_exists($field, $validated) && $validated[$field] != $exam->{$field}) {
                    throw ValidationException::withMessages([
                        $field => ['This exam detail cannot be changed after marks have been recorded.'],
                    ]);
                }
            }
        }

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
        $validated = $request->validate([
            'exam_id' => 'required|exists:exams,id',
            'grades'  => 'required|array|min:1',
            'grades.*.student_id'     => 'required|integer|distinct|exists:students,id',
            'grades.*.marks_obtained' => 'required|numeric|min:0',
            'grades.*.remarks'        => 'nullable|string|max:2000',
        ]);

        $exam = Exam::whereHas('classRoom', fn ($query) =>
            $query->where('school_id', currentSchoolId())
        )->findOrFail($validated['exam_id']);
        if ($exam->status === 'cancelled') {
            throw ValidationException::withMessages([
                'exam_id' => ['Marks cannot be entered for a cancelled exam.'],
            ]);
        }
        if ($exam->status !== 'completed') {
            throw ValidationException::withMessages([
                'exam_id' => ['Marks can only be entered for completed exams.'],
            ]);
        }

        $schoolStudentIds = Student::query()
            ->where('school_id', currentSchoolId())
            ->where('class_id', $exam->class_id)
            ->whereIn('id', array_column($validated['grades'], 'student_id'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($validated['grades'] as $index => $entry) {
            if (!in_array((int) $entry['student_id'], $schoolStudentIds, true)) {
                throw ValidationException::withMessages([
                    "grades.{$index}.student_id" => ['This student is not enrolled in the exam class.'],
                ]);
            }
            if ((float) $entry['marks_obtained'] > $exam->total_marks) {
                throw ValidationException::withMessages([
                    "grades.{$index}.marks_obtained" => ["Marks cannot exceed {$exam->total_marks}."],
                ]);
            }
        }

        DB::transaction(function () use ($validated, $exam) {
            foreach ($validated['grades'] as $entry) {
                $percentage = round(($entry['marks_obtained'] / $exam->total_marks) * 100, 1);
                $letterGrade = $this->calculateLetterGrade($percentage);

                Grade::updateOrCreate(
                    ['exam_id' => $exam->id, 'student_id' => $entry['student_id']],
                    [
                        'class_id'       => $exam->class_id,
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

        });

        return response()->json([
            'success' => true,
            'message' => 'Marks saved for ' . count($validated['grades']) . ' students.',
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
