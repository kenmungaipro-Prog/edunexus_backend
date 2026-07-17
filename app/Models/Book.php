<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Book extends Model
{
    protected $table = 'books';

    protected $fillable = [
        'book_id',
        'title',
        'author',
        'isbn',
        'category',
        'publisher',
        'year',
        'total_copies',
        'available_copies',
        'rack_no',
    ];

    protected $casts = [
        'year' => 'integer',
        'total_copies' => 'integer',
        'available_copies' => 'integer',
    ];

    // Relationships
    public function bookIssues(): HasMany
    {
        return $this->hasMany(BookIssue::class);
    }
}
