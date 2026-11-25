<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    // Test 1: Guest cannot view users
    public function test_guest_cannot_view_users(): void
    {
        $this->getJson('/admin/users')
            ->assertUnauthorized();
    }

    // Test 2: Staff cannot view users
    public function test_staff_cannot_view_users(): void
    {
        $staff = User::factory()->create([
            'role' => 'staff',
            'is_active' => true,
        ]);

        $this->actingAs($staff)
            ->getJson('/admin/users')
            ->assertForbidden();
    }

    // Test 3: Admin can view users
    public function test_admin_can_view_users(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/users')
            ->assertOk()
            ->assertJsonPath('users.0.id', $admin->id);
    }

    // Test 4: Admin can create staff
    public function test_admin_can_create_staff(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)
            ->postJson('/admin/users', [
                'name' => 'Test Staff',
                'email' => 'teststaff@example.com',
                'password' => 'StrongTest123!',
                'role' => 'staff',
            ]);

        $response->assertCreated()
            ->assertJsonPath('user.role', 'staff');

        $staff = User::where(
            'email',
            'teststaff@example.com'
        )->firstOrFail();

        $this->assertTrue(
            Hash::check('StrongTest123!', $staff->password)
        );
    }

    // Test 5: Admin can edit staff
    public function test_admin_can_edit_staff(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        $staff = User::factory()->create([
            'role' => 'staff',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->putJson('/admin/users/' . $staff->id, [
                'name' => 'Updated Staff',
                'is_active' => false,
            ])
            ->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $staff->id,
            'name' => 'Updated Staff',
            'is_active' => false,
        ]);
    }
}
