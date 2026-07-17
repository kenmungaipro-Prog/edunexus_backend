<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChartOfAccount extends Model
{
    protected $table = 'chart_of_accounts';

    protected $fillable = [
        'school_id',
        'parent_account_id',
        'account_code',
        'account_name',
        'account_type',
        'normal_balance',
        'currency',
        'is_control_account',
        'is_bank_account',
        'is_system',
        'is_active',
    ];

    protected $casts = [
        'is_control_account' => 'boolean',
        'is_bank_account' => 'boolean',
        'is_system' => 'boolean',
        'is_active' => 'boolean',
    ];

    // Relationships
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'parent_account_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(ChartOfAccount::class, 'parent_account_id');
    }

    public function journalEntryLines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class, 'chart_of_account_id');
    }
}
