<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{TimetableSlot, ClassRoom, Subject, Teacher, Book, BookIssue, Route, Event, Message, User};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\DB;

// ============================================================
// EventController
// ============================================================

class EventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $events = Event::where('school_id', currentSchoolId())
            ->when($request->type,     fn($q, $v) => $q->where('type', $v))
            ->when($request->upcoming, fn($q)     => $q->where('event_date', '>=', now()))
            ->orderBy('event_date')
            ->paginate($request->per_page ?? 20);

        return response()->json(['success' => true, 'data' => $events]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
            'event_date'  => 'required|date',
            'end_date'    => 'nullable|date|after_or_equal:event_date',
            'type'        => 'required|in:event,exam,holiday,meeting,competition',
            'venue'       => 'nullable|string|max:255',
            'notify_all'  => 'boolean',
        ]);

        $event = Event::create([
            ...$validated,
            'school_id'  => currentSchoolId(),
            'created_by' => auth()->id(),
        ]);

        return response()->json(['success' => true, 'data' => $event], 201);
    }

    public function show(Event $event): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $event]);
    }

    public function update(Request $request, Event $event): JsonResponse
    {
        $validated = $request->validate([
            'title'       => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'event_date'  => 'sometimes|required|date',
            'end_date'    => 'nullable|date|after_or_equal:event_date',
            'type'        => 'sometimes|required|in:event,exam,holiday,meeting,competition',
            'venue'       => 'nullable|string|max:255',
            'notify_all'  => 'boolean',
        ]);

        $event->update($validated);
        return response()->json(['success' => true, 'data' => $event->fresh()]);
    }

    public function destroy(Event $event): JsonResponse
    {
        $event->delete();
        return response()->json(['success' => true, 'message' => 'Event deleted.']);
    }
}
