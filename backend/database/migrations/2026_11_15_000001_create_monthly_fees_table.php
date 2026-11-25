<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('monthly_fees')) {
            if (!Schema::hasTable('residents')) {
                // Only the isolated testing database may get a minimal stand-in table.
                // Any other environment (local, shared Neon, production) still fails closed.
                if (!app()->environment('testing')) {
                    throw new \RuntimeException(
                        "Prerequisite table 'residents' does not exist. Cannot create 'monthly_fees' without its required foreign key constraint to residents(resident_id). Please install the prerequisite residents schema first."
                    );
                }

                Schema::create('residents', function (Blueprint $table) {
                    $table->increments('resident_id');
                    $table->string('resident_code', 20)->unique();
                    $table->string('full_name', 100);
                    $table->string('email', 100)->unique();
                    $table->string('phone', 20)->nullable();
                    $table->string('status', 20)->default('ACTIVE');
                    $table->timestamp('created_at')->useCurrent();
                });
            }

            Schema::create('monthly_fees', function (Blueprint $table) {
                $table->id('fee_id');
                $table->string('fee_code', 30)->unique();
                $table->unsignedInteger('resident_id');
                $table->string('billing_period', 7); // Format: YYYY-MM
                $table->decimal('amount_due', 10, 2);
                $table->date('due_date');
                $table->string('status', 20)->default('UNPAID');
                $table->string('remarks', 255)->nullable();
                $table->timestamps();

                // Unique billing constraint per resident per period
                $table->unique(['resident_id', 'billing_period'], 'uq_resident_billing_period');

                // Performance indexes
                $table->index('billing_period', 'idx_monthly_fees_period');
                $table->index('resident_id', 'idx_monthly_fees_resident');
                $table->index('status', 'idx_monthly_fees_status');

                // Mandatory foreign key constraint to residents
                $table->foreign('resident_id')
                    ->references('resident_id')
                    ->on('residents')
                    ->onDelete('restrict');
            });

            // PostgreSQL CHECK constraint for billing period format and positive amount
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("ALTER TABLE monthly_fees ADD CONSTRAINT chk_billing_period_fmt CHECK (billing_period ~ '^\\d{4}-(0[1-9]|1[0-2])$')");
                DB::statement("ALTER TABLE monthly_fees ADD CONSTRAINT chk_amount_due_positive CHECK (amount_due > 0)");
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monthly_fees');
    }
};