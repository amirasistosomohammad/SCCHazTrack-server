<?php

namespace App\Http\Controllers;

use App\Models\HazardCategory;
use App\Models\Location;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class SystemManagerController extends Controller
{
    public function listUsers(Request $request)
    {
        /** @var User $actor */
        $actor = $request->user();
        if ($actor->role !== User::ROLE_ADMIN) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $perPage = min(max((int) $request->query('per_page', 10), 1), 50);

        $q = trim((string) $request->query('q', ''));

        $query = User::query()->orderBy('id');
        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            });
        }

        return response()->json($query->paginate($perPage));
    }

    public function createUser(Request $request)
    {
        /** @var User $actor */
        $actor = $request->user();
        if ($actor->role !== User::ROLE_ADMIN) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'string', Rule::in([User::ROLE_REPORTER, User::ROLE_ADMIN])],
            'department' => ['nullable', 'string'],
            'phone' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => $data['role'],
            'department' => $data['department'] ?? null,
            'phone' => $data['phone'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        return response()->json(['data' => $user], 201);
    }

    public function updateUser(Request $request, int $id)
    {
        /** @var User $actor */
        $actor = $request->user();
        if ($actor->role !== User::ROLE_ADMIN) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $user = User::query()->findOrFail($id);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2'],
            'email' => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
            'role' => ['sometimes', 'string', Rule::in([User::ROLE_REPORTER, User::ROLE_ADMIN])],
            'department' => ['sometimes', 'nullable', 'string'],
            'phone' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('password', $data) && $data['password']) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $user->fill($data);
        if (array_key_exists('is_active', $data) && $data['is_active'] === true) {
            // Clear deactivation notes when re-activating.
            $user->deactivation_remarks = null;
        }
        $user->save();

        return response()->json(['data' => $user]);
    }

    public function deactivateUser(Request $request, int $id)
    {
        /** @var User $actor */
        $actor = $request->user();
        if ($actor->role !== User::ROLE_ADMIN) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $user = User::query()->findOrFail($id);

        if ($user->id === $actor->id) {
            return response()->json(['message' => 'You cannot deactivate your own account.'], 422);
        }

        $data = $request->validate([
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $user->forceFill([
            'is_active' => false,
            'deactivation_remarks' => $data['remarks'] ?? null,
        ])->save();

        // Invalidate all existing sessions/tokens immediately so the reporter gets logged out.
        $user->tokens()->delete();

        return response()->json(['data' => $user]);
    }

    public function deleteUser(Request $request, int $id)
    {
        /** @var User $actor */
        $actor = $request->user();
        if ($actor->role !== User::ROLE_ADMIN) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $user = User::query()->findOrFail($id);
        if ($user->id === $actor->id) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }

        $user->delete();

        return response()->json(['ok' => true]);
    }

    public function createCategory(Request $request)
    {
        /** @var User $actor */
        $actor = $request->user();
        if ($actor->role !== User::ROLE_ADMIN) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'unique:hazard_categories,name'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);

        $category = HazardCategory::query()->create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        return response()->json(['data' => $category], 201);
    }

    public function updateCategory(Request $request, int $id)
    {
        /** @var User $actor */
        $actor = $request->user();
        if ($actor->role !== User::ROLE_ADMIN) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $category = HazardCategory::query()->findOrFail($id);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', Rule::unique('hazard_categories', 'name')->ignore($category->id)],
            'description' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $category->fill($data)->save();

        return response()->json(['data' => $category]);
    }

    public function createLocation(Request $request)
    {
        /** @var User $actor */
        $actor = $request->user();
        if ($actor->role !== User::ROLE_ADMIN) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2'],
            'description' => ['nullable', 'string'],
            'parent_id' => ['nullable', 'integer', 'exists:locations,id'],
            'is_active' => ['boolean'],
        ]);

        $location = Location::query()->create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'parent_id' => $data['parent_id'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        return response()->json(['data' => $location], 201);
    }

    public function updateLocation(Request $request, int $id)
    {
        /** @var User $actor */
        $actor = $request->user();
        if ($actor->role !== User::ROLE_ADMIN) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $location = Location::query()->findOrFail($id);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2'],
            'description' => ['sometimes', 'nullable', 'string'],
            'parent_id' => ['sometimes', 'nullable', 'integer', 'exists:locations,id'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $location->fill($data)->save();

        return response()->json(['data' => $location]);
    }
}

