<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class SharedUsersMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function replaceUsersWithLegacySchema(bool $uniqueEmail = true): void
    {
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table) use ($uniqueEmail): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            if ($uniqueEmail) {
                $table->unique('email');
            }
            $table->string('password');
            $table->timestamps();
        });
    }

    public function test_legacy_users_are_preserved_and_given_staff_defaults(): void
    {
        $this->replaceUsersWithLegacySchema();

        DB::table('users')->insert([
            'name' => 'Existing Team Member',
            'email' => 'existing@example.test',
            'password' => 'existing-hash-placeholder',
        ]);

        $migration = require database_path(
            'migrations/2026_11_16_000003_reconcile_shared_user_access_fields.php'
        );
        $migration->up();

        $this->assertTrue(Schema::hasColumn('users', 'role'));
        $this->assertTrue(Schema::hasColumn('users', 'is_active'));
        $this->assertDatabaseHas('users', [
            'email' => 'existing@example.test',
            'role' => 'staff',
            'is_active' => true,
            'password' => 'existing-hash-placeholder',
        ]);
    }

    public function test_existing_role_assignments_remain_unchanged(): void
    {
        DB::table('users')->insert([
            'name' => 'Current Administrator',
            'email' => 'current-admin@example.test',
            'password' => 'existing-hash-placeholder',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $migration = require database_path(
            'migrations/2026_11_16_000003_reconcile_shared_user_access_fields.php'
        );
        $migration->up();

        $this->assertDatabaseHas('users', [
            'email' => 'current-admin@example.test',
            'role' => 'admin',
            'is_active' => true,
        ]);
    }

    public function test_missing_unique_email_index_stops_migration_before_writing(): void
    {
        $this->replaceUsersWithLegacySchema(false);

        $migration = require database_path(
            'migrations/2026_11_16_000003_reconcile_shared_user_access_fields.php'
        );

        $this->expectException(RuntimeException::class);
        $migration->up();
    }
}
