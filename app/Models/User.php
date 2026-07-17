<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'school_id',
        'name',
        'email',
        'password',
        'role',
        'status',
        'profile_photo',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
    ];

    // Relationships
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function teacher(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Teacher::class);
    }

    public function parentProfile(): HasOne
    {
        return $this->hasOne(ParentProfile::class, 'user_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Student::class, 'parent_id', 'id');
    }

    public function sentMessages(): HasMany
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    public function receivedMessages(): HasMany
    {
        return $this->hasMany(Message::class, 'receiver_id');
    }

    public function createdEvents(): HasMany
    {
        return $this->hasMany(Event::class, 'created_by');
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(Attendance::class, 'marked_by');
    }

    public function examsCreated(): HasMany
    {
        return $this->hasMany(Exam::class, 'created_by');
    }

    public function gradesEntered(): HasMany
    {
        return $this->hasMany(Grade::class, 'entered_by');
    }

    public function feesCollected(): HasMany
    {
        return $this->hasMany(Fee::class, 'collected_by');
    }

    public function invoicesCreated(): HasMany
    {
        return $this->hasMany(Invoice::class, 'created_by');
    }

    public function paymentsReceived(): HasMany
    {
        return $this->hasMany(Payment::class, 'received_by');
    }

    public function bookIssuesCreated(): HasMany
    {
        return $this->hasMany(BookIssue::class, 'issued_by');
    }

    public function bookIssuesMembership(): HasMany
    {
        return $this->hasMany(BookIssue::class, 'member_id');
    }

    public function journalEntriesCreated(): HasMany
    {
        return $this->hasMany(JournalEntry::class, 'created_by');
    }

    public function hasRole(string $role): bool
    {
        return $this->role === $role;
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole('superadmin');
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    public function isParent(): bool
    {
        return $this->hasRole('parent');
    }

    public function isAccountant(): bool
    {
        return $this->hasRole('accountant');
    }
}
