<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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

    // Update an existing user
    public function update(
        Request $request,
        User $user
    ): JsonResponse {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string',
                       'max:255'],
            'email' => ['sometimes', 'required', 'email',
                        'max:255',
                        Rule::unique('users', 'email')
                            ->ignore($user->id)],
            'password' => ['sometimes', 'required',
                           'string', 'min:12'],
            'role' => ['sometimes',
                       Rule::in(['admin', 'staff'])],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        // Prevent admin from removing their own access
        if ($request->user()->id === $user->id) {
            if (
                (isset($data['role']) && $data['role'] !== 'admin') ||
                (array_key_exists('is_active', $data) &&
                 !$data['is_active'])
            ) {
                return response()->json([
                    'message' => 'Cannot disable or demote your own admin account.',
                ], 422);
            }
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
            'user' => $user->only([
                'id', 'name', 'email', 'role', 'is_active'
            ]),
        ]);
    }
}
