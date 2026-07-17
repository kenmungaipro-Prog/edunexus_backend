<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{TimetableSlot, ClassRoom, Subject, Teacher, Book, BookIssue, Route, Event, Message, User};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\DB;


// ============================================================
// MessageController
// ============================================================

class MessageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $threads = Message::with(['sender', 'receiver'])
            ->where(fn($q) => $q->where('sender_id', auth()->id())->orWhere('receiver_id', auth()->id()))
            ->latest()
            ->get()
            ->groupBy(fn($m) => $m->sender_id === auth()->id() ? $m->receiver_id : $m->sender_id)
            ->map(fn($msgs) => [
                'contact'    => $msgs->first()->sender_id === auth()->id()
                    ? $msgs->first()->receiver
                    : $msgs->first()->sender,
                'last_message' => $msgs->first(),
                'unread'       => $msgs->where('receiver_id', auth()->id())->where('read_at', null)->count(),
            ])
            ->values();

        return response()->json(['success' => true, 'data' => $threads]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'receiver_id' => 'required|exists:users,id',
            'body'        => 'required|string|max:5000',
            'subject'     => 'nullable|string|max:255',
        ]);

        $message = Message::create([
            'sender_id'   => auth()->id(),
            'receiver_id' => $request->receiver_id,
            'body'        => $request->body,
            'subject'     => $request->subject,
        ]);

        return response()->json(['success' => true, 'data' => $message->load('sender', 'receiver')], 201);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $messages = Message::with(['sender', 'receiver'])
            ->where(fn($q) => $q
                ->where('sender_id', auth()->id())->where('receiver_id', $user->id)
                ->orWhere('sender_id', $user->id)->where('receiver_id', auth()->id())
            )
            ->orderBy('created_at')
            ->get();

        // Mark all as read
        Message::where('sender_id', $user->id)
            ->where('receiver_id', auth()->id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['success' => true, 'data' => $messages]);
    }
}

