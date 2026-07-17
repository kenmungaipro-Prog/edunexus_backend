<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ParentProfile extends Model
{
    protected $table = 'parent_profiles';

    protected $fillable = [
        'school_id',
        'user_id',
        'relationship',
        'phone',
        'whatsapp_phone',
        'preferred_whatsapp',
        'address',
        'occupation',
        'notes',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function children(): HasMany
    {
        return $this->hasMany(Student::class, 'parent_id', 'user_id');
    }
}
