<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAllocation extends Model
{
    protected $table = 'payment_allocations';

    protected $fillable = [
        'payment_id',
        'invoice_id',
        'amount_allocated',
        'allocated_by',
        'allocated_at',
    ];

    protected $casts = [
        'amount_allocated' => 'decimal:2',
        'allocated_at' => 'datetime',
    ];

    // Relationships
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function allocatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'allocated_by');
    }
}
