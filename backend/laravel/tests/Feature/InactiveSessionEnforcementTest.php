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
}
