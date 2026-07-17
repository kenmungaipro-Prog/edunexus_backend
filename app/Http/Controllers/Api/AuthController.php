<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string|min:6',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Your account has been deactivated. Contact the administrator.',
            ], 403);
        }

        $user->tokens()->where('name', 'api')->delete();

        $token = $user->createToken('api', $this->getAbilitiesForRole($user->role))->plainTextToken;

        $user->update(['last_login_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'Login successful.',
            'data'    => [
                'user'  => $user->only(['id', 'name', 'email', 'role', 'profile_photo']),
                'token' => $token,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        if ($token) {
            if (method_exists($token, 'delete')) {
                $token->delete();
            } elseif (method_exists($token, 'forceDelete')) {
                $token->forceDelete();
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully.',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $request->user()->load('school'),
        ]);
    }

    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user->currentAccessToken();

        if ($token && method_exists($token, 'delete')) {
            $token->delete();
        }

        $token = $user->createToken('api', $this->getAbilitiesForRole($user->role))->plainTextToken;

        return response()->json([
            'success' => true,
            'data'    => ['token' => $token],
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required|string',
            'password'         => 'required|string|min:8|confirmed',
        ]);

        if (! Hash::check($request->current_password, $request->user()->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $request->user()->update(['password' => Hash::make($request->password)]);

        return response()->json([
            'success' => true,
            'message' => 'Password updated successfully.',
        ]);
    }

    private function getAbilitiesForRole(string $role): array
    {
        return match ($role) {
            'superadmin' => ['*'],
            'admin'      => ['read', 'create', 'update', 'delete'],
            'teacher'    => ['read', 'attendance:mark', 'grades:enter'],
            'accountant' => ['read', 'fees:manage'],
            'librarian'  => ['read', 'library:manage'],
            'receptionist' => ['read', 'students:create'],
            'student'    => ['profile:read', 'grades:read', 'attendance:read'],
            'parent'     => ['children:read'],
            default      => ['read'],
        };
    }
}
