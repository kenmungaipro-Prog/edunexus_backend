<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookIssue extends Model
{
    protected $table = 'book_issues';

    protected $fillable = [
        'book_id',
        'member_id',
        'issued_by',
        'issued_at',
        'due_date',
        'returned_at',
        'status',
        'fine_amount',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'due_date' => 'date',
        'returned_at' => 'datetime',
        'fine_amount' => 'decimal:2',
    ];

    // Relationships
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
