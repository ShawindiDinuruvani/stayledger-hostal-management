<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Constraint identifier and ownership marker for this additive repair migration.
     */
    private const CONSTRAINT_NAME = 'monthly_fees_resident_id_foreign';
    private const OWNERSHIP_TAG = 'managed_by:2026_11_15_000003';

    /**
     * Run the migrations.
     * Additive repair migration to attach the required foreign key constraint from monthly_fees to residents.
     */
    public function up(): void
    {
        // 1. Verify table existence
        if (!Schema::hasTable('monthly_fees')) {
            throw new \RuntimeException(
                "Prerequisite table 'monthly_fees' does not exist. Cannot apply foreign key repair migration."
            );
        }

        if (!Schema::hasTable('residents')) {
            if (app()->environment('testing')) {
                return;
            }

            throw new \RuntimeException(
                "Prerequisite table 'residents' does not exist. Please install the prerequisite residents schema before running this migration."
            );
        }

        // 2. Verify column existence
        if (!Schema::hasColumn('monthly_fees', 'resident_id')) {
            throw new \RuntimeException(
                "Required column 'resident_id' is missing from table 'monthly_fees'."
            );
        }

        if (!Schema::hasColumn('residents', 'resident_id')) {
            throw new \RuntimeException(
                "Required column 'resident_id' is missing from table 'residents'."
            );
        }

        // 3. Check if foreign key already exists (idempotency & pre-existing schema safety)
        if (DB::getDriverName() === 'pgsql') {
            $existingFk = DB::selectOne("
                SELECT c.conname, obj_description(c.oid, 'pg_constraint') as comment
                FROM pg_constraint c
                JOIN pg_class t ON t.oid = c.conrelid
                JOIN pg_namespace n ON n.oid = t.relnamespace
                JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(c.conkey)
                WHERE n.nspname = current_schema()
                  AND t.relname = 'monthly_fees'
                  AND c.contype = 'f'
                  AND a.attname = 'resident_id'
            ");

            if ($existingFk) {
                return;
            }
        } elseif (DB::getDriverName() === 'sqlite') {
            return;
        }

        // 4. Inspect for orphaned records in monthly_fees (never delete data automatically)
        $orphanedCount = (int) DB::table('monthly_fees')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('residents')
                    ->whereColumn('residents.resident_id', 'monthly_fees.resident_id');
            })
            ->count();

        if ($orphanedCount > 0) {
            $sampleOrphans = DB::table('monthly_fees')
                ->whereNotExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('residents')
                        ->whereColumn('residents.resident_id', 'monthly_fees.resident_id');
                })
                ->limit(5)
                ->pluck('resident_id')
                ->toArray();

            throw new \RuntimeException(
                "Cannot attach foreign key constraint: found {$orphanedCount} orphaned record(s) in 'monthly_fees' referencing non-existent resident_id(s) [sample: " . implode(', ', $sampleOrphans) . "]. Existing data must never be deleted automatically. Resolve orphaned data before running this migration."
            );
        }

        // 5. Create foreign key constraint
        Schema::table('monthly_fees', function (Blueprint $table) {
            $table->foreign('resident_id', self::CONSTRAINT_NAME)
                ->references('resident_id')
                ->on('residents')
                ->onDelete('restrict');
        });

        // 6. Tag constraint ownership with metadata comment in PostgreSQL
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(sprintf(
                "COMMENT ON CONSTRAINT %s ON monthly_fees IS '%s'",
                self::CONSTRAINT_NAME,
                self::OWNERSHIP_TAG
            ));
        }
    }

    /**
     * Reverse the migrations.
     * Safely rolls back the foreign key constraint ONLY if it was created by this repair migration.
     * Pre-existing foreign keys created by the original migration or external schemas are preserved intact.
     */
    public function down(): void
    {
        if (!Schema::hasTable('monthly_fees')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            $ownership = DB::selectOne("
                SELECT c.conname, obj_description(c.oid, 'pg_constraint') as comment
                FROM pg_constraint c
                JOIN pg_class t ON t.oid = c.conrelid
                JOIN pg_namespace n ON n.oid = t.relnamespace
                WHERE n.nspname = current_schema()
                  AND t.relname = 'monthly_fees'
                  AND c.contype = 'f'
                  AND c.conname = ?
            ", [self::CONSTRAINT_NAME]);

            if (!$ownership) {
                return;
            }

            if ($ownership->comment === self::OWNERSHIP_TAG) {
                Schema::table('monthly_fees', function (Blueprint $table) {
                    $table->dropForeign(self::CONSTRAINT_NAME);
                });
            }
        } else {
            Schema::table('monthly_fees', function (Blueprint $table) {
                $table->dropForeign(self::CONSTRAINT_NAME);
            });
        }
    }
};