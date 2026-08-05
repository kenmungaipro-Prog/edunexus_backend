<?php

if (!function_exists('currentSchoolId')) {
    /**
     * Get the current school ID from the request attribute or authenticated user.
     */
    function currentSchoolId(): int
    {
        // Pulls from SchoolMiddleware attribute, falls back to auth user's school_id, or defaults to 1
        return request()->attributes->get('school_id') 
            ?? auth()->user()?->school_id 
            ?? 1;
    }
}