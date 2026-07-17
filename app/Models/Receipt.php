<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Receipt extends Model
{
    protected $table = 'receipts';

    protected $fillable = [
        'school_id',
        'payment_id',
        'receipt_number',
        'receipt_date',
        'issued_by',
        'status',
        'pdf_path',
        'qr_code',
    ];

    protected $casts = [
        'receipt_date' => 'date',
    ];

    // Relationships
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
