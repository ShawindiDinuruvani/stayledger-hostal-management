<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountEmailRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_mixed_case_email_can_log_in(): void
    {
        User::factory()->create([
            'email' => 'team.staff@example.test',
            'password' => Hash::make('StrongPassword123!'),
            'role' => 'staff',
            'is_active' => true,
        ]);

        $this->postJson('/login', [
            'email' => 'TEAM.Staff@Example.Test',
            'password' => 'StrongPassword123!',
        ])->assertOk()->assertJsonPath('user.email', 'team.staff@example.test');
    }

    public function test_new_account_email_and_name_are_trimmed_and_email_lowercased(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin)->postJson('/admin/users', [
            'name' => '  New Staff  ',
            'email' => '  New.STAFF@Example.Test  ',
            'password' => 'StrongPassword123!',
            'role' => 'staff',
        ])->assertCreated()->assertJsonPath('user.email', 'new.staff@example.test');

        $this->assertDatabaseHas('users', [
            'name' => 'New Staff',
            'email' => 'new.staff@example.test',
        ]);
    }

    public function test_email_case_variation_cannot_duplicate_existing_legacy_user(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        User::factory()->create(['email' => 'Legacy.User@Example.Test']);

        $this->actingAs($admin)->postJson('/admin/users', [
            'name' => 'Duplicate',
            'email' => 'legacy.user@example.test',
            'password' => 'StrongPassword123!',
            'role' => 'staff',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_email_update_rejects_case_insensitive_conflict(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        User::factory()->create(['email' => 'Existing.User@Example.Test']);
        $staff = User::factory()->create(['role' => 'staff']);

        $this->actingAs($admin)->putJson('/admin/users/'.$staff->id, [
            'email' => 'EXISTING.user@example.test',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }
}
