<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_change_a_password(): void
    {
        $this->postJson('/account/password', [])->assertUnauthorized();
    }

    public function test_staff_changes_password_and_current_session_ends(): void
    {
        $staff = User::factory()->create([
            'role' => 'staff',
            'is_active' => true,
            'password' => Hash::make('OriginalPass123!'),
        ]);

        $this->actingAs($staff)->postJson('/account/password', [
            'current_password' => 'OriginalPass123!',
            'password' => 'ReplacementPass456!',
            'password_confirmation' => 'ReplacementPass456!',
        ])->assertOk();

        $this->assertGuest();
        $this->assertTrue(Hash::check('ReplacementPass456!', $staff->fresh()->password));

        $this->postJson('/login', [
            'email' => $staff->email,
            'password' => 'OriginalPass123!',
        ])->assertUnauthorized();

        $this->postJson('/login', [
            'email' => $staff->email,
            'password' => 'ReplacementPass456!',
        ])->assertOk();
    }

    public function test_wrong_current_password_is_rejected(): void
    {
        $staff = User::factory()->create([
            'is_active' => true,
            'password' => Hash::make('OriginalPass123!'),
        ]);

        $this->actingAs($staff)->postJson('/account/password', [
            'current_password' => 'IncorrectPass987!',
            'password' => 'ReplacementPass456!',
            'password_confirmation' => 'ReplacementPass456!',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('OriginalPass123!', $staff->fresh()->password));
    }

    public function test_password_confirmation_and_length_are_validated(): void
    {
        $staff = User::factory()->create(['is_active' => true]);

        $this->actingAs($staff)->postJson('/account/password', [
            'current_password' => 'password',
            'password' => 'short',
            'password_confirmation' => 'mismatch',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_reusing_current_password_is_rejected(): void
    {
        $staff = User::factory()->create([
            'is_active' => true,
            'password' => Hash::make('OriginalPass123!'),
        ]);

        $this->actingAs($staff)->postJson('/account/password', [
            'current_password' => 'OriginalPass123!',
            'password' => 'OriginalPass123!',
            'password_confirmation' => 'OriginalPass123!',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }
}
