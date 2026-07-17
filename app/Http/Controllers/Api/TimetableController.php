<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{TimetableSlot, ClassRoom, Subject, Teacher, Book, BookIssue, Route, Event, Message, User};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\DB;

// ============================================================
// TimetableController
// ============================================================

class TimetableController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['class_id' => 'required|exists:class_rooms,id']);

        $slots = TimetableSlot::with(['subject', 'teacher.user'])
            ->where('class_id', $request->class_id)
            ->orderBy('day_of_week')
            ->orderBy('period_number')
            ->get()
            ->groupBy('day_of_week');

        return response()->json(['success' => true, 'data' => $slots]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'class_id'      => 'required|exists:class_rooms,id',
            'subject_id'    => 'required|exists:subjects,id',
            'teacher_id'    => 'required|exists:teachers,id',
            'day_of_week'   => 'required|integer|between:1,6',
            'period_number' => 'required|integer|between:1,8',
            'start_time'    => 'required|date_format:H:i',
            'end_time'      => 'required|date_format:H:i|after:start_time',
            'room'          => 'nullable|string|max:50',
        ]);

        // Check teacher conflict
        $conflict = TimetableSlot::where('teacher_id', $request->teacher_id)
            ->where('day_of_week', $request->day_of_week)
            ->where('period_number', $request->period_number)
            ->exists();

        if ($conflict) {
            return response()->json([
                'success' => false,
                'message' => 'Teacher already has a class during this period.',
            ], 422);
        }

        $slot = TimetableSlot::updateOrCreate(
            ['class_id' => $request->class_id, 'day_of_week' => $request->day_of_week, 'period_number' => $request->period_number],
            $request->validated()
        );

        return response()->json([
            'success' => true,
            'message' => 'Timetable slot saved.',
            'data'    => $slot->load('subject', 'teacher.user'),
        ]);
    }

    public function autoGenerate(Request $request): JsonResponse
    {
        $request->validate(['class_id' => 'required|exists:class_rooms,id']);

        // Auto-generate based on subjects assigned to class
        $classRoom = ClassRoom::with('subjects.teachers')->findOrFail($request->class_id);
        $days = [1, 2, 3, 4, 5, 6];
        $periods = [1, 2, 3, 4, 5, 6];
        $slots = [];

        TimetableSlot::where('class_id', $request->class_id)->delete();

        $subjects = $classRoom->subjects->shuffle();
        $subjectIndex = 0;

        foreach ($days as $day) {
            foreach ($periods as $period) {
                if ($subjectIndex >= $subjects->count()) $subjectIndex = 0;
                $subject = $subjects[$subjectIndex++];
                $teacher = $subject->teachers->first();
                if (!$teacher) continue;

                $startHour = 8 + $period;
                $slots[] = TimetableSlot::create([
                    'class_id'      => $request->class_id,
                    'subject_id'    => $subject->id,
                    'teacher_id'    => $teacher->id,
                    'day_of_week'   => $day,
                    'period_number' => $period,
                    'start_time'    => sprintf('%02d:00', $startHour),
                    'end_time'      => sprintf('%02d:00', $startHour + 1),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => count($slots) . ' timetable slots generated.',
        ]);
    }

    public function update(Request $request, TimetableSlot $timetableSlot): JsonResponse
    {
        $timetableSlot->update($request->only(['teacher_id', 'subject_id', 'room', 'start_time', 'end_time']));

        return response()->json([
            'success' => true,
            'data'    => $timetableSlot->fresh(['subject', 'teacher.user']),
        ]);
    }
}
