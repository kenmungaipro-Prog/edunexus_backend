<?php

// ============================================================
// app/Policies/StudentPolicy.php
// ============================================================
namespace App\Policies;

use App\Models\Student;
use App\Models\User;

class StudentPolicy
{
    public function before(User $user): ?bool
    {
        if ($user->isSuperAdmin()) return true;
        return null;
    }

    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['admin', 'teacher', 'receptionist', 'accountant']);
    }

    public function view(User $user, Student $student): bool
    {
        if ($user->school_id !== $student->school_id) return false;
        if ($user->isParent()) return $student->parent_id === $user->id;
        return in_array($user->role, ['admin', 'teacher', 'receptionist', 'accountant']);
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['admin', 'receptionist']);
    }

    public function update(User $user, Student $student): bool
    {
        return $user->school_id === $student->school_id
            && in_array($user->role, ['admin', 'receptionist']);
    }

    public function delete(User $user, Student $student): bool
    {
        return $user->school_id === $student->school_id && $user->isAdmin();
    }
}
