<?php

// ============================================================
// app/Http/Middleware/SchoolMiddleware.php
// Ensures all data is scoped to the authenticated user's school
// ============================================================
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;



class SchoolMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        // If sanctum didn't find a user, let the auth:sanctum middleware handle the 401
        // If sanctum didn't find a user, let the auth:sanctum middleware handle the 401
        if (!$request->user()) {
            return $next($request); 
        }

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        if (! $user->school_id && $user->role !== 'superadmin') {
            return response()->json([
                'success' => false,
                'message' => 'No school associated with this account.',
            ], 403);
        }

        // Inject school_id into request for controllers to use
        $request->merge(['_school_id' => $user->school_id]);

        // Apply global query scope via service container
        app()->instance('current_school_id', $user->school_id);

        return $next($request);
    }
}