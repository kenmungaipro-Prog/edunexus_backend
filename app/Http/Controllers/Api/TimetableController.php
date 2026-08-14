<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{TimetableSlot, ClassRoom, Subject, Teacher, Book, BookIssue, Route, Event, Message, User};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

// ============================================================
// TimetableController
// ============================================================

class TimetableController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'class_id'         => 'sometimes|nullable|exists:class_rooms,id',
            'teacher_id'       => 'sometimes|nullable|exists:teachers,id',
            'subject_id'       => 'sometimes|nullable|exists:subjects,id',
            'day_of_week'      => 'sometimes|nullable|integer|between:1,6',
            'start_time_from'  => 'sometimes|nullable|date_format:H:i',
            'start_time_to'    => 'sometimes|nullable|date_format:H:i',
            'end_time_from'    => 'sometimes|nullable|date_format:H:i',
            'end_time_to'      => 'sometimes|nullable|date_format:H:i',
        ]);

        $query = TimetableSlot::with(['subject', 'teacher.user']);

        if ($request->filled('class_id')) {
            $query->where('class_id', $request->class_id);
        }

        if ($request->filled('teacher_id')) {
            $query->where('teacher_id', $request->teacher_id);
        }

        if ($request->filled('subject_id')) {
            $query->where('subject_id', $request->subject_id);
        }

        if ($request->filled('day_of_week')) {
            $query->where('day_of_week', $request->day_of_week);
        }

        if ($request->filled('start_time_from')) {
            $query->where('start_time', '>=', $request->start_time_from);
        }

        if ($request->filled('start_time_to')) {
            $query->where('start_time', '<=', $request->start_time_to);
        }

        if ($request->filled('end_time_from')) {
            $query->where('end_time', '>=', $request->end_time_from);
        }

        if ($request->filled('end_time_to')) {
            $query->where('end_time', '<=', $request->end_time_to);
        }

        $slots = $query
            ->orderBy('day_of_week')
            ->orderBy('period_number')
            ->get()
            ->groupBy('day_of_week');

        // default settings if no class provided
        $settings = [
            'total_periods'             => 8,
            'period_duration_minutes'   => 60,
            'day_start_time'            => '08:00',
            'break_after_period'        => null,
            'break_duration_minutes'    => 15,
            'lunch_after_period'        => null,
            'lunch_duration_minutes'    => 45,
        ];

        if ($request->filled('class_id')) {
            $classRoom = ClassRoom::findOrFail($request->class_id);
            $settings = [
                'total_periods'             => $classRoom->total_periods ?? 8,
                'period_duration_minutes'   => $classRoom->period_duration_minutes ?? 60,
                'day_start_time'            => $classRoom->day_start_time ? substr($classRoom->day_start_time, 0, 5) : '08:00',
                'break_after_period'        => $classRoom->break_after_period,
                'break_duration_minutes'    => $classRoom->break_duration_minutes ?? 15,
                'lunch_after_period'        => $classRoom->lunch_after_period,
                'lunch_duration_minutes'    => $classRoom->lunch_duration_minutes ?? 45,
            ];
        }

        return response()->json([
            'success' => true,
            'data'    => $slots,
            'settings' => $settings,
        ]);
    }

    public function updateSettings(Request $request, ClassRoom $classRoom): JsonResponse
    {
        $validated = $request->validate([
            'total_periods'             => 'required|integer|between:4,12',
            'period_duration_minutes'   => 'required|integer|between:30,120',
            'day_start_time'            => 'required|date_format:H:i',
            'break_after_period'        => 'nullable|integer',
            'break_duration_minutes'    => 'required|integer|between:5,60',
            'lunch_after_period'        => 'nullable|integer',
            'lunch_duration_minutes'    => 'required|integer|between:15,120',
        ]);

        $classRoom->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Class timetable settings updated successfully.',
            'data'    => $classRoom,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'class_id'      => 'required|exists:class_rooms,id',
            'slot_type'     => 'required|in:class,break,lunch,event',
            'day_of_week'   => 'required|integer|between:1,6',
            'period_number' => 'required|integer',
            'start_time'    => 'required|date_format:H:i',
            'end_time'      => 'required|date_format:H:i|after:start_time',
            'subject_id'    => 'required_if:slot_type,class|nullable|exists:subjects,id',
            'teacher_id'    => 'required_if:slot_type,class|nullable|exists:teachers,id',
            'title'         => 'nullable|string|max:100',
            'room'          => 'nullable|string|max:50',
        ]);

        // Check teacher conflict if it's a regular academic class assignment
        if ($request->slot_type === 'class' && $request->teacher_id) {
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
        }

        $slot = TimetableSlot::updateOrCreate(
            ['class_id' => $request->class_id, 'day_of_week' => $request->day_of_week, 'period_number' => $request->period_number],
            $request->all()
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

        $classRoom = ClassRoom::with('subjects.teachers')->findOrFail($request->class_id);
        $days = [1, 2, 3, 4, 5, 6];
        $totalPeriods = $classRoom->total_periods ?? 8;
        $periods = range(1, $totalPeriods);
        $slots = [];

        $subjects = $classRoom->subjects->shuffle()->values();
        if ($subjects->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot generate timetable: this class has no subjects assigned.',
            ], 422);
        }

        TimetableSlot::where('class_id', $request->class_id)->delete();

        $subjectIndex = 0;

        foreach ($days as $day) {
            $currentTime = Carbon::createFromFormat('H:i', $classRoom->day_start_time ? substr($classRoom->day_start_time, 0, 5) : '08:00');

            foreach ($periods as $period) {
                $startTime = $currentTime->copy();
                $endTime = $startTime->copy()->addMinutes($classRoom->period_duration_minutes ?? 60);
                $currentTime = $endTime->copy();

                // Check if short break follows this period
                if ($classRoom->break_after_period == $period) {
                    $breakStart = $currentTime->copy();
                    $breakEnd = $breakStart->copy()->addMinutes($classRoom->break_duration_minutes ?? 15);
                    
                    TimetableSlot::create([
                        'class_id'      => $request->class_id,
                        'slot_type'     => 'break',
                        'title'         => 'Recess / Break',
                        'day_of_week'   => $day,
                        'period_number' => $period * 10 + 1,
                        'start_time'    => $breakStart->format('H:i'),
                        'end_time'      => $breakEnd->format('H:i'),
                    ]);
                    $currentTime = $breakEnd->copy();
                }

                // Check if lunch follows this period
                if ($classRoom->lunch_after_period == $period) {
                    $lunchStart = $currentTime->copy();
                    $lunchEnd = $lunchStart->copy()->addMinutes($classRoom->lunch_duration_minutes ?? 45);
                    
                    TimetableSlot::create([
                        'class_id'      => $request->class_id,
                        'slot_type'     => 'lunch',
                        'title'         => 'Lunch Break',
                        'day_of_week'   => $day,
                        'period_number' => $period * 10 + 2,
                        'start_time'    => $lunchStart->format('H:i'),
                        'end_time'      => $lunchEnd->format('H:i'),
                    ]);
                    $currentTime = $lunchEnd->copy();
                }

                if ($subjectIndex >= $subjects->count()) {
                    $subjectIndex = 0;
                }
                $subject = $subjects[$subjectIndex++];
                $teacher = $subject->teachers->first();
                if (!$teacher) {
                    continue;
                }

                $slots[] = TimetableSlot::create([
                    'class_id'      => $request->class_id,
                    'slot_type'     => 'class',
                    'subject_id'    => $subject->id,
                    'teacher_id'    => $teacher->id,
                    'day_of_week'   => $day,
                    'period_number' => $period,
                    'start_time'    => $startTime->format('H:i'),
                    'end_time'      => $endTime->format('H:i'),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => count($slots) . ' timetable slots generated successfully with custom timing rules.',
        ]);
    }

    public function update(Request $request, TimetableSlot $slot): JsonResponse
    {
        $validated = $request->validate([
            'slot_type'     => 'required|in:class,break,lunch,event',
            'start_time'    => 'required|date_format:H:i',
            'end_time'      => 'required|date_format:H:i|after:start_time',
            'subject_id'    => 'required_if:slot_type,class|nullable|exists:subjects,id',
            'teacher_id'    => 'required_if:slot_type,class|nullable|exists:teachers,id',
            'title'         => 'nullable|string|max:100',
            'room'          => 'nullable|string|max:50',
        ]);

        $slot->update($validated);

        return response()->json([
            'success' => true,
            'data'    => $slot->fresh(['subject', 'teacher.user']),
        ]);
    }

    public function destroy(TimetableSlot $slot): JsonResponse
    {
        $slot->delete();

        return response()->json([
            'success' => true,
            'message' => 'Timetable slot deleted successfully.',
        ]);
    }
}