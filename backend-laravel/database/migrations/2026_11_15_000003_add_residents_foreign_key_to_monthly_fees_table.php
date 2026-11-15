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

        if (DB::getDriverName() === 'pgsql') {
            // 3. Verify that residents.resident_id is an individually referenceable primary key or unique key
            $isIndividuallyReferenceable = (bool) DB::scalar("
                SELECT EXISTS (
                    SELECT 1
                    FROM pg_constraint c
                    JOIN pg_class t ON t.oid = c.conrelid
                    JOIN pg_namespace n ON n.oid = t.relnamespace
                    JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(c.conkey)
                    WHERE n.nspname = current_schema()
                      AND t.relname = 'residents'
                      AND c.contype IN ('p', 'u')
                      AND array_length(c.conkey, 1) = 1
                      AND a.attname = 'resident_id'
                )
            ");

            if (!$isIndividuallyReferenceable) {
                throw new \RuntimeException(
                    "Column 'resident_id' on table 'residents' is not an individually referenceable primary key or unique key constraint. Cannot reference residents(resident_id)."
                );
            }

            // 4. Schema-scoped data type and udt compatibility check
            $typeCheck = DB::selectOne("
                SELECT 
                    (c1.data_type = c2.data_type AND COALESCE(c1.udt_name, '') = COALESCE(c2.udt_name, '')) AS matches,
                    c1.data_type AS fee_type,
                    c2.data_type AS res_type
                FROM information_schema.columns c1
                CROSS JOIN information_schema.columns c2
                WHERE c1.table_schema = current_schema()
                  AND c1.table_name = 'monthly_fees'
                  AND c1.column_name = 'resident_id'
                  AND c2.table_schema = current_schema()
                  AND c2.table_name = 'residents'
                  AND c2.column_name = 'resident_id'
            ");

            if (!$typeCheck || !$typeCheck->matches) {
                $feeType = $typeCheck->fee_type ?? 'unknown';
                $resType = $typeCheck->res_type ?? 'unknown';
                throw new \RuntimeException(
                    "Data type mismatch: monthly_fees.resident_id ({$feeType}) does not match residents.resident_id ({$resType}). Cannot create foreign key."
                );
            }

            // 5. Existing foreign key inspection (evaluating actual definition, not merely name)
            $existingFk = DB::selectOne("
                SELECT 
                    c.conname,
                    c.confrelid::regclass::text as ref_table,
                    ra.attname as ref_column,
                    c.confdeltype as del_type,
                    pg_get_constraintdef(c.oid) as def
                FROM pg_constraint c
                JOIN pg_class t ON t.oid = c.conrelid
                JOIN pg_namespace n ON n.oid = t.relnamespace
                JOIN pg_attribute fa ON fa.attrelid = t.oid AND fa.attnum = ANY(c.conkey)
                JOIN pg_attribute ra ON ra.attrelid = c.confrelid AND ra.attnum = ANY(c.confkey)
                WHERE n.nspname = current_schema()
                  AND t.relname = 'monthly_fees'
                  AND c.contype = 'f'
                  AND fa.attname = 'resident_id'
                  AND array_length(c.conkey, 1) = 1
            ");

            if ($existingFk) {
                // Check if the existing foreign key correctly references residents(resident_id) ON DELETE RESTRICT
                $isTargetResidents = in_array($existingFk->ref_table, ['residents', 'public.residents', '"residents"', '"public"."residents"'], true);
                $isTargetResidentId = ($existingFk->ref_column === 'resident_id');
                $isRestrict = in_array($existingFk->del_type, ['r', 'a'], true); // 'r' = RESTRICT, 'a' = NO ACTION

                if ($isTargetResidents && $isTargetResidentId && $isRestrict) {
                    // Pre-existing valid foreign key constraint from original migration or earlier install.
                    // Keep intact; no action required.
                    return;
                }

                // If existing FK conflicts in table, column, or delete rule, halt with conflict error
                throw new \RuntimeException(
                    "Conflicting foreign key constraint '{$existingFk->conname}' already exists on monthly_fees(resident_id) with definition: {$existingFk->def}. Expected reference to residents(resident_id) ON DELETE RESTRICT."
                );
            }
        }

        // 6. Inspect for orphaned records in monthly_fees (never delete data automatically)
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

        // 7. Create foreign key constraint
        Schema::table('monthly_fees', function (Blueprint $table) {
            $table->foreign('resident_id', self::CONSTRAINT_NAME)
                ->references('resident_id')
                ->on('residents')
                ->onDelete('restrict');
        });

        // 8. Tag constraint ownership with metadata comment in PostgreSQL
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
            // Check ownership tag
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
                // Constraint does not exist; nothing to rollback
                return;
            }

            if ($ownership->comment === self::OWNERSHIP_TAG) {
                // Created by this repair migration; drop safely
                Schema::table('monthly_fees', function (Blueprint $table) {
                    $table->dropForeign(self::CONSTRAINT_NAME);
                });
            } else {
                // Pre-existing constraint not created by this migration; preserve intact
                // Do not drop.
            }
        } else {
            Schema::table('monthly_fees', function (Blueprint $table) {
                $table->dropForeign(self::CONSTRAINT_NAME);
            });
        }
    }
};
