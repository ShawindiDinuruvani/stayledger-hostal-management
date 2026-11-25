<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordResetSessionRevocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_login_session_expires_after_an_admin_resets_the_password(): void
    {
        $staff = User::factory()->create([
            'role' => 'staff',
            'is_active' => true,
            'password' => Hash::make('InitialPassword123!'),
        ]);

        $this->postJson('/login', [
            'email' => $staff->email,
            'password' => 'InitialPassword123!',
        ])->assertOk();

        $this->getJson('/me')->assertOk();

        // Simulate the administrator password reset in a separate browser session.
        $staff->forceFill([
            'password' => Hash::make('ResetPassword456!'),
        ])->save();

        $this->getJson('/me')->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_active_session_remains_valid_when_password_is_unchanged(): void
    {
        $staff = User::factory()->create([
            'role' => 'staff',
            'is_active' => true,
            'password' => Hash::make('InitialPassword123!'),
        ]);

        $this->postJson('/login', [
            'email' => $staff->email,
            'password' => 'InitialPassword123!',
        ])->assertOk();

        $this->getJson('/me')->assertOk();
    }
}
