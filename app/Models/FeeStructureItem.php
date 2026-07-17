<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeeStructureItem extends Model
{
    protected $table = 'fee_structure_items';

    protected $fillable = [
        'fee_structure_id',
        'fee_category_id',
        'description',
        'amount',
        'is_mandatory',
        'is_recurring',
        'revenue_account_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'is_mandatory' => 'boolean',
        'is_recurring' => 'boolean',
    ];

    // Relationships
    public function feeStructure(): BelongsTo
    {
        return $this->belongsTo(FeeStructure::class);
    }

    public function feeCategory(): BelongsTo
    {
        return $this->belongsTo(FeeCategory::class);
    }
}
