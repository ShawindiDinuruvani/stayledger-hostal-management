<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthLoginTest extends TestCase
{
    use RefreshDatabase;

    // Test 1: Successful Login
    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'password' => 'TestPassword123!',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $response = $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'TestPassword123!',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Login successful.');

        $this->assertAuthenticatedAs($user);
    }

    // Test 2: Incorrect Password
    public function test_login_fails_with_wrong_password(): void
    {
        $user = User::factory()->create([
            'password' => 'TestPassword123!',
        ]);

        $response = $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'WrongPassword123!',
        ]);

        $response->assertStatus(401);

        $this->assertGuest();
    }

    // Test 3: Inactive Account
    public function test_inactive_user_cannot_login(): void
    {
        $user = User::factory()->create([
            'password' => 'TestPassword123!',
            'is_active' => false,
        ]);

        $response = $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'TestPassword123!',
        ]);

        $response->assertStatus(401);

        $this->assertGuest();
    }

    // Test 4: Successful Logout
    public function test_user_can_logout(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
        ]);

        $this->actingAs($user);

        $response = $this->postJson('/logout');

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Logout successful.');

        $this->assertGuest();
    }
}
