<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalEntry extends Model
{
    protected $table = 'journal_entries';

    protected $fillable = [
        'school_id',
        'entry_date',
        'description',
        'reference',
        'source_module',
        'source_id',
        'status',
        'created_by',
    ];

    protected $casts = [
        'entry_date' => 'date',
    ];

    // Relationships
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function creator(): BelongsTo
    {
        return $this->createdBy();
    }

    public function journalEntryLines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    public function lines(): HasMany
    {
        return $this->journalEntryLines();
    }

    public function getTotalDebit(): float
    {
        return $this->journalEntryLines->sum('debit');
    }

    public function getTotalCredit(): float
    {
        return $this->journalEntryLines->sum('credit');
    }

    public function isBalanced(): bool
    {
        return abs($this->getTotalDebit() - $this->getTotalCredit()) < 0.01;
    }
}
