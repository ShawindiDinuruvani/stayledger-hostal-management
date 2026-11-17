<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntegrationVerifyTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonymous_session_cannot_be_verified(): void
    {
        $this->getJson('/me')->assertUnauthorized();
        $this->postJson('/integration/verify')->assertUnauthorized();
    }

    public function test_authenticated_active_session_can_be_verified(): void
    {
        $user = User::factory()->create([
            'role' => 'staff',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->postJson('/integration/verify')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.role', 'staff');
    }

    public function test_deactivated_account_cannot_be_verified(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
        ]);
        $this->actingAs($user);
        $user->update(['is_active' => false]);

        $this->postJson('/integration/verify')->assertUnauthorized();
    }
}
