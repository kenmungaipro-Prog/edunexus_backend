<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ParentProfile;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ParentProfileController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();

        if ($user->isParent()) {
            $profile = ParentProfile::with(['user', 'school', 'children'])
                ->where('user_id', $user->id)
                ->firstOrFail();

            return response()->json([
                'success' => true,
                'data' => [
                    'data' => [$profile],
                    'meta' => [
                        'current_page' => 1,
                        'last_page' => 1,
                        'per_page' => $request->per_page ?? 20,
                        'total' => 1,
                        'from' => 1,
                        'to' => 1,
                    ],
                    'links' => [
                        'first' => null,
                        'last' => null,
                        'prev' => null,
                        'next' => null,
                    ],
                ],
            ]);
        }

        $profiles = ParentProfile::with(['user', 'school'])
            ->when($request->search, fn($q, $value) => $q->where(function ($query) use ($value) {
                $query->whereHas('user', fn($u) => $u->where('name', 'like', "%{$value}%")->orWhere('email', 'like', "%{$value}%"))
                      ->orWhere('phone', 'like', "%{$value}%")
                      ->orWhere('relationship', 'like', "%{$value}%");
            }))
            ->paginate($request->per_page ?? 20);

        return response()->json(['success' => true, 'data' => $profiles]);
    }

    public function stats(): JsonResponse
    {
        $this->authorizeRoles(['admin', 'receptionist']);

        $schoolId = currentSchoolId();

        $total = ParentProfile::where('school_id', $schoolId)->count();
        $active = ParentProfile::where('school_id', $schoolId)
            ->whereHas('user', fn($query) => $query->where('status', 'active'))
            ->count();
        $inactive = ParentProfile::where('school_id', $schoolId)
            ->whereHas('user', fn($query) => $query->where('status', 'inactive'))
            ->count();
        $parentsWithNoChildren = ParentProfile::where('school_id', $schoolId)
            ->doesntHave('children')
            ->count();
        $parentsWithMultipleChildren = ParentProfile::where('school_id', $schoolId)
            ->has('children', '>', 1)
            ->count();
        $totalChildren = Student::where('school_id', $schoolId)
            ->whereNotNull('parent_id')
            ->count();

        $relationshipBreakdown = ParentProfile::where('school_id', $schoolId)
            ->select('relationship')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('relationship')
            ->get()
            ->mapWithKeys(fn($row) => [
                $row->relationship ? $row->relationship : 'Other' => (int) $row->count,
            ])
            ->all();

        $occupationBreakdown = ParentProfile::where('school_id', $schoolId)
            ->select('occupation')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('occupation')
            ->orderByDesc('count')
            ->limit(10)
            ->get()
            ->map(fn($row) => [
                'occupation' => $row->occupation ? $row->occupation : 'Unknown',
                'count' => (int) $row->count,
            ])
            ->all();

        return response()->json([
            'success' => true,
            'data'    => [
                'total' => $total,
                'active' => $active,
                'inactive' => $inactive,
                'parents_with_no_children' => $parentsWithNoChildren,
                'parents_with_multiple_children' => $parentsWithMultipleChildren,
                'total_children' => $totalChildren,
                'relationship_breakdown' => $relationshipBreakdown,
                'occupation_breakdown' => $occupationBreakdown,
                'age_groups' => [],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeRoles(['admin', 'receptionist']);

        $request->validate([
            'name'         => 'required|string|max:255',
            'email'        => 'required|email|unique:users',
            'password'     => 'nullable|string|min:8',
            'relationship' => 'nullable|string|max:100',
            'phone'        => 'nullable|string|max:20',
            'address'      => 'nullable|string|max:65000',
            'occupation'   => 'nullable|string|max:255',
            'notes'        => 'nullable|string|max:65000',
        ]);

        $profile = DB::transaction(function () use ($request) {
            $schoolId = auth()->user()->school_id;

            $user = User::create([
                'school_id' => $schoolId,
                'name'      => $request->name,
                'email'     => $request->email,
                'password'  => Hash::make($request->password ?? 'Parent@123'),
                'role'      => 'parent',
                'status'    => 'active',
            ]);

            return ParentProfile::create([
                'school_id'   => $schoolId,
                'user_id'     => $user->id,
                'relationship'=> $request->relationship,
                'phone'       => $request->phone,
                'address'     => $request->address,
                'occupation'  => $request->occupation,
                'notes'       => $request->notes,
            ]);
        });

        return response()->json(['success' => true, 'message' => 'Parent profile created.', 'data' => $profile->load(['user', 'school'])], 201);
    }

    public function show(ParentProfile $parent): JsonResponse
    {
        $this->authorizeParent($parent);

        return response()->json(['success' => true, 'data' => $parent->load(['user', 'school', 'children'])]);
    }

    public function update(Request $request, ParentProfile $parent): JsonResponse
    {
        $this->authorizeParent($parent);

        $request->validate([
            'name'         => 'sometimes|string|max:255',
            'email'        => 'sometimes|email|unique:users,email,' . $parent->user_id,
            'password'     => 'sometimes|nullable|string|min:8',
            'relationship' => 'sometimes|nullable|string|max:100',
            'phone'        => 'sometimes|nullable|string|max:20',
            'address'      => 'sometimes|nullable|string|max:65000',
            'occupation'   => 'sometimes|nullable|string|max:255',
            'notes'        => 'sometimes|nullable|string|max:65000',
        ]);

        DB::transaction(function () use ($request, $parent) {
            $profileData = $request->only(['relationship', 'phone', 'address', 'occupation', 'notes']);
            $profileData = array_filter($profileData, fn($value) => $value !== null);

            if (!empty($profileData)) {
                $parent->update($profileData);
            }

            if ($request->filled('name')) {
                $parent->user->update(['name' => $request->name]);
            }
            if ($request->filled('email')) {
                $parent->user->update(['email' => $request->email]);
            }
            if ($request->filled('password')) {
                $parent->user->update(['password' => Hash::make($request->password)]);
            }
        });

        return response()->json(['success' => true, 'message' => 'Parent profile updated.', 'data' => $parent->fresh(['user', 'school', 'children'])]);
    }

    public function destroy(ParentProfile $parent): JsonResponse
    {
        $this->authorizeRoles(['admin']);

        DB::transaction(function () use ($parent) {
            $parent->user->update(['status' => 'inactive']);
            $parent->delete();
        });

        return response()->json(['success' => true, 'message' => 'Parent profile deactivated.']);
    }

    public function me(): JsonResponse
    {
        $user = auth()->user();

        if (!$user->isParent()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $profile = ParentProfile::with(['user', 'school', 'children'])
            ->where('user_id', $user->id)
            ->firstOrFail();

        return response()->json(['success' => true, 'data' => $profile]);
    }

    private function authorizeParent(ParentProfile $profile): void
    {
        $user = auth()->user();

        if ($user->isSuperAdmin()) {
            return;
        }

        if ($user->isParent() && $user->id !== $profile->user_id) {
            abort(403, 'Unauthorized.');
        }

        if (!in_array($user->role, ['admin', 'receptionist', 'parent'])) {
            abort(403, 'Unauthorized.');
        }
    }

    private function authorizeRoles(array $roles): void
    {
        $user = auth()->user();

        if ($user->isSuperAdmin()) {
            return;
        }

        if (!in_array($user->role, $roles)) {
            abort(403, 'Unauthorized.');
        }
    }
}
