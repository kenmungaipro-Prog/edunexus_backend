<?php
// ============================================================
// app/helpers.php
// Register in composer.json: "files": ["app/helpers.php"]
// ============================================================

if (! function_exists('currentSession')) {
    /**
     * Get the ID of the currently active academic session.
     */
    function currentSession(): ?int
    {
        return \App\Models\AcademicSession::where('is_current', true)->value('id');
    }
}

if (! function_exists('currentSchoolId')) {
    /**
     * Get the school ID of the currently authenticated user.
     * Injected by SchoolMiddleware into the service container.
     */
    function currentSchoolId(): ?int
    {
        if (app()->bound('current_school_id')) {
            return app('current_school_id');
        }

        return auth()->user()?->school_id;
    }
}

if (! function_exists('currentSchool')) {
    /**
     * Get the currently authenticated user's school model.
     */
    function currentSchool(): ?\App\Models\School
    {
        $id = currentSchoolId();
        return $id ? \App\Models\School::find($id) : null;
    }
}

if (! function_exists('formatCurrency')) {
    /**
     * Format a number using the current school's currency.
     */
    function formatCurrency(float|int|string $amount, bool $symbol = true): string
    {
        $formatted = number_format((float) $amount, 2);

        if (! $symbol) {
            return $formatted;
        }

        $currency = currentSchool()?->currency ?? config('app.currency', 'KES');

        return "{$currency} {$formatted}";
    }
}

if (! function_exists('letterGrade')) {
    /**
     * Convert a percentage to a letter grade.
     */
    function letterGrade(float $percentage): string
    {
        return match (true) {
            $percentage >= 90 => 'A+',
            $percentage >= 80 => 'A',
            $percentage >= 70 => 'B+',
            $percentage >= 60 => 'B',
            $percentage >= 50 => 'C',
            $percentage >= 35 => 'D',
            default           => 'F',
        };
    }
}

if (! function_exists('generateAdmissionNo')) {
    function generateAdmissionNo(int $schoolId): string
    {
        $year  = now()->format('Y');
        $count = \App\Models\Student::where('school_id', $schoolId)
            ->whereYear('created_at', $year)->count() + 1;
        return "ADM-{$year}-" . str_pad($count, 4, '0', STR_PAD_LEFT);
    }
}
