<?php
// ============================================================
// app/Http/Middleware/RoleMiddleware.php
// ============================================================
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     * Usage: ->middleware('role:admin,teacher')
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        // Superadmin bypasses all role checks
        if ($user->role === 'superadmin') {
            return $next($request);
        }

        if (! in_array($user->role, $roles)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to perform this action.',
                'required_roles' => $roles,
                'your_role'      => $user->role,
            ], 403);
        }

        return $next($request);
    }
}
