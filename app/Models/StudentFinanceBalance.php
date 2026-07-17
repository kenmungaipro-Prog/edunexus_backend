<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentFinanceBalance extends Model
{
    protected $table = 'student_finance_balances';

    protected $fillable = [
        'school_id',
        'student_id',
        'currency',
        'invoiced_total',
        'paid_total',
        'credit_total',
        'balance',
        'last_updated_at',
    ];

    protected $casts = [
        'invoiced_total' => 'decimal:2',
        'paid_total' => 'decimal:2',
        'credit_total' => 'decimal:2',
        'balance' => 'decimal:2',
        'last_updated_at' => 'datetime',
    ];

    // Relationships
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
