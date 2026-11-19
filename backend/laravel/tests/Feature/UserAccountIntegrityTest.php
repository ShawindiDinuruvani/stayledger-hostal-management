<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserAccountIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    public function test_staff_cannot_create_an_account(): void
    {
        $staff = User::factory()->create(['role' => 'staff', 'is_active' => true]);

        $this->actingAs($staff)
            ->postJson('/admin/users', [
                'name' => 'Unauthorised',
                'email' => 'unauthorised@example.test',
                'password' => 'StrongerPassword123!',
                'role' => 'admin',
            ])
            ->assertForbidden();
    }

    public function test_user_creation_hashes_password_and_never_returns_it(): void
    {
        $response = $this->actingAs($this->admin())
            ->postJson('/admin/users', [
                'name' => 'New Staff',
                'email' => 'new-staff@example.test',
                'password' => 'StrongerPassword123!',
                'role' => 'staff',
                'is_active' => true,
            ])
            ->assertCreated();

        $response->assertJsonMissingPath('user.password');
        $staff = User::where('email', 'new-staff@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('StrongerPassword123!', $staff->password));
        $this->assertNotSame('StrongerPassword123!', $staff->password);
    }

    public function test_admin_cannot_disable_or_demote_own_account(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->putJson('/admin/users/'.$admin->id, ['is_active' => false])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->putJson('/admin/users/'.$admin->id, ['role' => 'staff'])
            ->assertStatus(422);

        $this->assertDatabaseHas('users', [
            'id' => $admin->id,
            'role' => 'admin',
            'is_active' => true,
        ]);
    }

    public function test_duplicate_email_and_invalid_role_are_rejected(): void
    {
        $admin = $this->admin();
        $existing = User::factory()->create(['role' => 'staff']);

        $this->actingAs($admin)
            ->postJson('/admin/users', [
                'name' => 'Duplicate',
                'email' => $existing->email,
                'password' => 'StrongerPassword123!',
                'role' => 'staff',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->actingAs($admin)
            ->postJson('/admin/users', [
                'name' => 'Invalid Role',
                'email' => 'invalid-role@example.test',
                'password' => 'StrongerPassword123!',
                'role' => 'superuser',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');
    }
}
