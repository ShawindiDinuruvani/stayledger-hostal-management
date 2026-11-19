<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InactiveSessionEnforcementTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_staff_session_is_revoked_when_account_becomes_inactive(): void
    {
        $staff = User::factory()->create([
            'role' => 'staff',
            'is_active' => true,
        ]);

        $this->actingAs($staff);
        $staff->forceFill(['is_active' => false])->save();

        $this->getJson('/me')->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_existing_admin_session_is_revoked_when_account_becomes_inactive(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->actingAs($admin);
        $admin->forceFill(['is_active' => false])->save();

        $this->getJson('/admin/users')->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_active_staff_session_still_reaches_profile(): void
    {
        $staff = User::factory()->create([
            'role' => 'staff',
            'is_active' => true,
        ]);

        $this->actingAs($staff)
            ->getJson('/me')
            ->assertOk()
            ->assertJsonPath('user.id', $staff->id);
    }

    public function test_existing_admin_session_loses_admin_api_access_after_demotion(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'password' => 'Password12345!',
        ]);

        $this->postJson('/login', [
            'email' => $admin->email,
            'password' => 'Password12345!',
        ])->assertOk();

        $this->getJson('/admin/users')->assertOk();

        // Model a permission change made by another administrator.
        $admin->forceFill(['role' => 'staff'])->save();

        $this->getJson('/admin/users')->assertForbidden();
        $this->getJson('/me')->assertOk()->assertJsonPath('user.role', 'staff');
    }

}
