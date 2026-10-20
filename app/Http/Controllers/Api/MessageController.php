<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Message, User};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Validation\ValidationException;


// ============================================================
// MessageController
// ============================================================

class MessageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $threads = Message::with([
                'sender:id,name,role,profile_photo',
                'receiver:id,name,role,profile_photo',
            ])
            ->where(fn($query) => $query->where('sender_id', $user->id)->orWhere('receiver_id', $user->id))
            ->whereHas('sender', fn(Builder $query) => $this->scopeToSchool($query, $user))
            ->whereHas('receiver', fn(Builder $query) => $this->scopeToSchool($query, $user))
            ->latest()
            ->get()
            ->groupBy(fn($message) => $message->sender_id === $user->id ? $message->receiver_id : $message->sender_id)
            ->map(fn($msgs) => [
                'contact'    => $msgs->first()->sender_id === $user->id
                    ? $msgs->first()->receiver
                    : $msgs->first()->sender,
                'last_message' => $msgs->first(),
                'unread'       => $msgs->where('receiver_id', $user->id)->where('read_at', null)->count(),
            ])
            ->values();

        return response()->json(['success' => true, 'data' => $threads]);
    }

    public function contacts(Request $request): JsonResponse
    {
        $user = $request->user();

        $contacts = $this->schoolUsers($user)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'role', 'profile_photo']);

        return response()->json(['success' => true, 'data' => $contacts]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $user = $request->user();
        $count = Message::where('receiver_id', $user->id)
            ->whereNull('read_at')
            ->whereHas('sender', fn(Builder $query) => $this->scopeToSchool($query, $user))
            ->count();

        return response()->json(['success' => true, 'data' => ['count' => $count]]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'receiver_id' => 'required|integer',
            'body'        => 'required|string|max:5000',
            'subject'     => 'nullable|string|max:255',
        ]);

        $sender = $request->user();
        $receiver = $this->schoolUsers($sender)
            ->where('status', 'active')
            ->whereKey($validated['receiver_id'])
            ->first();

        if (! $receiver) {
            throw ValidationException::withMessages([
                'receiver_id' => ['The selected recipient is unavailable.'],
            ]);
        }

        $message = Message::create([
            'sender_id'   => $sender->id,
            'receiver_id' => $receiver->id,
            'body'        => $validated['body'],
            'subject'     => $validated['subject'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'data' => $message->load([
                'sender:id,name,role,profile_photo',
                'receiver:id,name,role,profile_photo',
            ]),
        ], 201);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $currentUser = $request->user();
        $contact = $this->schoolUsers($currentUser)->whereKey($user->id)->first();

        abort_unless($contact, 404);

        Message::where('sender_id', $contact->id)
            ->where('receiver_id', $currentUser->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        $messages = Message::with([
                'sender:id,name,role,profile_photo',
                'receiver:id,name,role,profile_photo',
            ])
            ->where(fn($query) => $query
                ->where(fn($participants) => $participants
                    ->where('sender_id', $currentUser->id)
                    ->where('receiver_id', $contact->id))
                ->orWhere(fn($participants) => $participants
                    ->where('sender_id', $contact->id)
                    ->where('receiver_id', $currentUser->id))
            )
            ->whereHas('sender', fn(Builder $query) => $this->scopeToSchool($query, $currentUser))
            ->whereHas('receiver', fn(Builder $query) => $this->scopeToSchool($query, $currentUser))
            ->orderBy('created_at')
            ->get();

        return response()->json(['success' => true, 'data' => $messages]);
    }

    private function schoolUsers(User $user): Builder
    {
        $query = User::query()->where('id', '!=', $user->id);

        if ($user->school_id === null) {
            return $query->whereNull('school_id')->where('role', 'superadmin');
        }

        return $query->where('school_id', $user->school_id);
    }

    private function scopeToSchool(Builder $query, User $user): Builder
    {
        if ($user->school_id === null) {
            return $query->whereNull('school_id')->where('role', 'superadmin');
        }

        return $query->where('school_id', $user->school_id);
    }
}
