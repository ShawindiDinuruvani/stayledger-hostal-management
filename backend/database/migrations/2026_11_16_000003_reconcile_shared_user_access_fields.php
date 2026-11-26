<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add User Accounts & Access fields to an existing shared users table.
     * No table or existing account is ever dropped.
     */
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            throw new RuntimeException('Shared users table missing. Install the team base schema first.');
        }

        // Fail instead of accepting duplicate emails in an existing shared schema.
        $emailUnique = false;
        foreach (Schema::getIndexes('users') as $index) {
            if (($index['unique'] ?? false) && ($index['columns'] ?? []) === ['email']) {
                $emailUnique = true;
                break;
            }
        }

        if (! $emailUnique) {
            throw new RuntimeException('users.email must have a UNIQUE index before enabling accounts.');
        }

        // Legacy accounts default to staff: never grant admin privileges implicitly.
        if (! Schema::hasColumn('users', 'role')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('role', 20)->default('staff');
            });
        }

        if (! Schema::hasColumn('users', 'is_active')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->boolean('is_active')->default(true);
            });
        }

        // PostgreSQL does not enforce Laravel validation on direct SQL writes.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'stayledger_users_role_check'
          AND conrelid = 'public.users'::regclass
    ) THEN
        ALTER TABLE public.users
            ADD CONSTRAINT stayledger_users_role_check
            CHECK (role IN ('admin', 'staff'));
    END IF;
END
$$;
SQL);
        }
    }

    public function down(): void
    {
        // Deliberately non-destructive. Shared accounts may serve other modules.
    }
};
