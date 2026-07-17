<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{TimetableSlot, ClassRoom, Subject, Teacher, Book, BookIssue, Route, Event, Message, User};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\DB;


// ============================================================
// LibraryController
// ============================================================

class LibraryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $books = Book::when($request->category, fn($q, $v) => $q->where('category', $v))
            ->when($request->search, fn($q, $v) => $q->where('title', 'like', "%{$v}%")->orWhere('author', 'like', "%{$v}%"))
            ->paginate($request->per_page ?? 20);

        return response()->json(['success' => true, 'data' => $books]);
    }

    public function store(Request $request): JsonResponse
    {
        // 1. Capture the returned array from the validate method
        $validated = $request->validate([
            'title'       => 'required|string|max:255',
            'author'      => 'required|string|max:255',
            'isbn'        => 'nullable|string|unique:books',
            'category'    => 'required|string|max:100',
            'publisher'   => 'nullable|string|max:255',
            'year'        => 'nullable|integer',
            'total_copies'=> 'required|integer|min:1',
            'rack_no'     => 'nullable|string|max:20',
        ]);

        $lastBook = Book::latest('id')->first();
        $nextId = $lastBook ? (int) filter_var($lastBook->book_id, FILTER_SANITIZE_NUMBER_INT) + 1 : 1;
        
        $book = Book::create([
            // 2. Spread the captured array here instead of $request->validated()
            ...$validated, 
            'book_id'          => 'B-' . str_pad($nextId, 4, '0', STR_PAD_LEFT),
            'available_copies' => $request->total_copies,
        ]);

        return response()->json(['success' => true, 'data' => $book], 201);
    }

    public function show($id): JsonResponse
        {
            // findOrFail will automatically throw a 404 if the book doesn't exist
            $book = Book::findOrFail($id);

            return response()->json([
                'success' => true,
                'data'    => $book
            ]);
        }
    public function issue(Request $request, Book $book): JsonResponse
    {
        $request->validate([
            'member_id'   => 'required|exists:users,id',
            'due_date'    => 'required|date|after:today',
        ]);

        if ($book->available_copies < 1) {
            return response()->json(['success' => false, 'message' => 'No copies available.'], 422);
        }

        DB::transaction(function () use ($request, $book) {
            BookIssue::create([
                'book_id'     => $book->id,
                'member_id'   => $request->member_id,
                'issued_by'   => auth()->id(),
                'issued_at'   => now(),
                'due_date'    => $request->due_date,
                'status'      => 'issued',
            ]);
            $book->decrement('available_copies');
        });

        return response()->json(['success' => true, 'message' => 'Book issued successfully.']);
    }

    public function return(Request $request, Book $book): JsonResponse
    {
        $issue = BookIssue::where('book_id', $book->id)
            ->where('member_id', $request->member_id)
            ->where('status', 'issued')
            ->firstOrFail();

        $fine = 0;
        if (now()->isAfter($issue->due_date)) {
            $overdueDays = now()->diffInDays($issue->due_date);
            $fine = $overdueDays * 2; // KES 2 per day
        }

        DB::transaction(function () use ($issue, $book, $fine) {
            $issue->update(['status' => 'returned', 'returned_at' => now(), 'fine_amount' => $fine]);
            $book->increment('available_copies');
        });

        return response()->json([
            'success' => true,
            'message' => 'Book returned.' . ($fine > 0 ? ' Fine: ' . formatCurrency($fine) : ''),
            'fine'    => $fine,
        ]);
    }

    public function stats()
        {
            return response()->json([
                'success' => true,
                'data' => [
                    'total_books'    => \App\Models\Book::sum('total_copies'),
                    'issued'         => \App\Models\BookIssue::where('status', 'issued')->count(),
                    'returned_today' => \App\Models\BookIssue::where('status', 'returned')
                                            ->whereDate('updated_at', today())
                                            ->count(),
                    'overdue'        => \App\Models\BookIssue::where('status', 'issued')
                                            ->where('due_date', '<', now())
                                            ->count(),
                ]
            ]);
        }

    public function overdue(): JsonResponse
    {
        $overdue = BookIssue::with(['book', 'member'])
            ->where('status', 'issued')
            ->where('due_date', '<', now())
            ->get()
            ->map(fn($i) => [
                'id'          => $i->id,
                'book'        => $i->book->title,
                'member'      => $i->member->name,
                'due_date'    => $i->due_date->format('d M Y'),
                'days_overdue'=> now()->diffInDays($i->due_date),
                'fine'        => now()->diffInDays($i->due_date) * 2,
            ]);

        return response()->json(['success' => true, 'data' => $overdue]);
    }
}
