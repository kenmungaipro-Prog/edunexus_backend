<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{TimetableSlot, ClassRoom, Subject, Teacher, Book, BookIssue, Route, Event, Message, User};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rule;


// ============================================================
// LibraryController
// ============================================================

class LibraryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'category' => 'nullable|string|max:100',
            'search' => 'nullable|string|max:255',
            'per_page' => 'sometimes|integer|between:1,100',
        ]);

        $books = Book::query()
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->where('category', $category))
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where(function ($bookQuery) use ($search) {
                    $bookQuery->where('title', 'like', "%{$search}%")
                        ->orWhere('author', 'like', "%{$search}%");
                });
            })
            ->orderBy('title')
            ->paginate($filters['per_page'] ?? 20);

        return response()->json(['success' => true, 'data' => $books]);
    }

    public function store(Request $request): JsonResponse
    {
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

        for ($attempt = 0; ; $attempt++) {
            try {
                $book = DB::transaction(function () use ($validated) {
                    Book::orderByDesc('id')->lockForUpdate()->first();

                    $bookIds = Book::pluck('book_id');
                    $highestNumber = $bookIds->reduce(function (int $highest, string $bookId): int {
                        if (preg_match('/^B-(\d+)$/', $bookId, $matches)) {
                            return max($highest, (int) $matches[1]);
                        }

                        return $highest;
                    }, 0);

                    do {
                        $candidate = 'B-' . str_pad(++$highestNumber, 4, '0', STR_PAD_LEFT);
                    } while ($bookIds->contains($candidate));

                    return Book::create([
                        ...$validated,
                        'book_id' => $candidate,
                        'available_copies' => $validated['total_copies'],
                    ]);
                });

                break;
            } catch (QueryException $exception) {
                $isBookIdCollision = ($exception->errorInfo[1] ?? null) === 1062
                    && str_contains($exception->getMessage(), 'books_book_id_unique');

                if (!$isBookIdCollision || $attempt >= 4) {
                    throw $exception;
                }
            }
        }

        return response()->json(['success' => true, 'data' => $book], 201);
    }

    public function show($id): JsonResponse
        {
            $book = Book::findOrFail($id);

            return response()->json([
                'success' => true,
                'data'    => $book
            ]);
        }

    public function members(): JsonResponse
    {
        $members = User::where('school_id', currentSchoolId())
            ->where('status', 'active')
            ->whereIn('role', ['student', 'teacher', 'parent'])
            ->orderBy('name')
            ->get(['id', 'name', 'role']);

        return response()->json(['success' => true, 'data' => $members]);
    }

    public function issues(Request $request, Book $book): JsonResponse
    {
        $status = $request->query('status');
        abort_if($status !== null && !in_array($status, ['issued', 'returned', 'lost'], true), 422, 'Invalid issue status.');

        $issues = $book->bookIssues()
            ->with(['member:id,name,role', 'issuedBy:id,name'])
            ->when($status, fn ($query) => $query->where('status', $status))
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->get();

        return response()->json(['success' => true, 'data' => $issues]);
    }

    public function update(Request $request, Book $book): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'author' => 'required|string|max:255',
            'isbn' => ['nullable', 'string', Rule::unique('books', 'isbn')->ignore($book->id)],
            'category' => 'required|string|max:100',
            'publisher' => 'nullable|string|max:255',
            'year' => 'nullable|integer|min:0|max:' . (now()->year + 1),
            'total_copies' => 'required|integer|min:1|max:65535',
            'rack_no' => 'nullable|string|max:20',
        ]);

        $updated = DB::transaction(function () use ($validated, $book): bool {
            $lockedBook = Book::whereKey($book->id)->lockForUpdate()->firstOrFail();
            $checkedOutCopies = $lockedBook->bookIssues()->where('status', 'issued')->count();

            if ($validated['total_copies'] < $checkedOutCopies) {
                return false;
            }

            $lockedBook->update([
                ...$validated,
                'available_copies' => $validated['total_copies'] - $checkedOutCopies,
            ]);

            return true;
        });

        if (!$updated) {
            return response()->json([
                'success' => false,
                'message' => 'Total copies cannot be fewer than the number currently checked out.',
            ], 422);
        }

        return response()->json(['success' => true, 'data' => $book->fresh()]);
    }

    public function issue(Request $request, Book $book): JsonResponse
    {
        $validated = $request->validate([
            'member_id'   => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query
                    ->where('school_id', currentSchoolId())
                    ->where('status', 'active')
                    ->whereIn('role', ['student', 'teacher', 'parent'])),
            ],
            'due_date'    => 'required|date|after:today',
        ]);

        DB::transaction(function () use ($validated, $book) {
            $lockedBook = Book::whereKey($book->id)->lockForUpdate()->firstOrFail();
            if ($lockedBook->available_copies < 1) {
                abort(422, 'No copies are currently available for this book.');
            }

            BookIssue::create([
                'book_id'     => $lockedBook->id,
                'member_id'   => $validated['member_id'],
                'issued_by'   => auth()->id(),
                'issued_at'   => now(),
                'due_date'    => $validated['due_date'],
                'status'      => 'issued',
            ]);
            $lockedBook->decrement('available_copies');
        });

        return response()->json([
            'success' => true,
            'message' => 'Book issued successfully.',
            'data' => $book->fresh(),
        ]);
    }

    public function destroy(Book $book): JsonResponse
    {
        $deleted = DB::transaction(function () use ($book): bool {
            $lockedBook = Book::whereKey($book->id)->lockForUpdate()->firstOrFail();

            if ($lockedBook->bookIssues()->where('status', 'issued')->exists()) {
                return false;
            }

            $lockedBook->delete();
            return true;
        });

        if (!$deleted) {
            return response()->json([
                'success' => false,
                'message' => 'This book cannot be deleted while copies are checked out. Record all returns first.',
            ], 422);
        }

        return response()->json(['success' => true, 'message' => 'Book deleted successfully.']);
    }

    public function return(Request $request, Book $book): JsonResponse
    {
        $validated = $request->validate([
            'issue_id' => 'required_without:member_id|nullable|integer',
            'member_id' => 'required_without:issue_id|nullable|integer',
        ]);

        $fine = DB::transaction(function () use ($validated, $book): float {
            $lockedBook = Book::whereKey($book->id)->lockForUpdate()->firstOrFail();
            $issue = BookIssue::where('book_id', $lockedBook->id)
                ->where('status', 'issued')
                ->when($validated['issue_id'] ?? null, fn ($query, $issueId) => $query->whereKey($issueId))
                ->when($validated['member_id'] ?? null, fn ($query, $memberId) => $query->where('member_id', $memberId))
                ->lockForUpdate()
                ->firstOrFail();

            $fine = $issue->due_date->lt(today())
                ? (float) $issue->due_date->diffInDays(today()) * 2
                : 0.0;

            $issue->update(['status' => 'returned', 'returned_at' => now(), 'fine_amount' => $fine]);
            $lockedBook->increment('available_copies');

            return $fine;
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
                    'total_titles'   => Book::count(),
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
