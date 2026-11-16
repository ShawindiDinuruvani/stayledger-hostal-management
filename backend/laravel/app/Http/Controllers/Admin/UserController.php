<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    // View all users
    public function index(): JsonResponse
    {
        $users = User::select(
            'id', 'name', 'email', 'role',
            'is_active', 'created_at'
        )->orderBy('id')->get();

        return response()->json(['users' => $users]);
    }

    // Create a new user
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255',
                        'unique:users,email'],
            'password' => ['required', 'string', 'min:12'],
            'role' => ['required', Rule::in(['admin', 'staff'])],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $user = new User();
        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->password = Hash::make($data['password']);
        $user->role = $data['role'];
        $user->is_active = $data['is_active'] ?? true;
        $user->save();

        return response()->json([
            'message' => 'User created successfully.',
            'user' => $user->only([
                'id', 'name', 'email', 'role', 'is_active'
            ]),
        ], 201);
    }

    // Update an existing user without allowing the final active administrator to disappear.
    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['sometimes', 'required', 'string', 'min:12'],
            'role' => ['sometimes', Rule::in(['admin', 'staff'])],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return DB::transaction(function () use ($request, $user, $data): JsonResponse {
            // Row locks make concurrent administrator changes safe on PostgreSQL.
            $activeAdminIds = User::query()
                ->where('role', 'admin')
                ->where('is_active', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id');

            $user->refresh();
            $removesAdminAccess =
                (array_key_exists('role', $data) && $data['role'] !== 'admin') ||
                (array_key_exists('is_active', $data) && ! $data['is_active']);

            if ($removesAdminAccess && $request->user()->id === $user->id) {
                return response()->json([
                    'message' => 'Cannot disable or demote your own admin account.',
                ], 422);
            }

            if (
                $removesAdminAccess &&
                $user->role === 'admin' &&
                $user->is_active &&
                $activeAdminIds->count() <= 1
            ) {
                return response()->json([
                    'message' => 'At least one active administrator must remain.',
                ], 422);
            }

            foreach (['name', 'email', 'role', 'is_active'] as $field) {
                if (array_key_exists($field, $data)) {
                    $user->$field = $data[$field];
                }
            }

            if (isset($data['password'])) {
                $user->password = Hash::make($data['password']);
            }

            $user->save();

            return response()->json([
                'message' => 'User updated successfully.',
                'user' => $user->only(['id', 'name', 'email', 'role', 'is_active']),
            ]);
        });
    }
}
