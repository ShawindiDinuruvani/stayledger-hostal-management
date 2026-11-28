<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class PaymentsModuleMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_incompatible_payments_table_is_repaired_in_place(): void
    {
        $this->createExistingPaymentsTable();

        $migration = require database_path('migrations/2026_11_28_000001_create_payments_module.php');
        $migration->up();

        $this->assertTrue(Schema::hasColumn('payments', 'fee_id'));
        $this->assertTrue(Schema::hasColumn('payments', 'payment_id'));
        $this->assertTrue(Schema::hasColumn('payments', 'payment_code'));
        $this->assertTrue(Schema::hasColumn('payments', 'resident_id'));
        $this->assertSame(0, DB::table('view_monthly_financial_summary')->value('payment_count'));
    }

    public function test_existing_payment_rows_are_preserved_when_fee_id_cannot_be_inferred(): void
    {
        $this->createExistingPaymentsTable();
        DB::table('payments')->insert([
            'resident_id' => 1,
            'amount' => 100,
            'paid_date' => '2026-11-10',
        ]);

        $migration = require database_path('migrations/2026_11_28_000001_create_payments_module.php');

        try {
            $migration->up();
            $this->fail('The migration should not assign an existing payment to an unknown monthly fee.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('contains records', $exception->getMessage());
        }

        $this->assertDatabaseHas('payments', ['resident_id' => 1, 'amount' => 100]);
        $this->assertFalse(Schema::hasColumn('payments', 'fee_id'));
    }

    private function createExistingPaymentsTable(): void
    {
        DB::statement('DROP VIEW IF EXISTS view_monthly_financial_summary');
        DB::statement('DROP VIEW IF EXISTS vw_fee_balance');
        DB::statement('DROP VIEW IF EXISTS vw_payment_history');
        Schema::drop('payments');
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('resident_id')->nullable();
            $table->decimal('amount', 10, 2);
            $table->date('paid_date');
        });
        DB::statement('CREATE VIEW view_monthly_financial_summary AS SELECT COUNT(*) AS payment_count FROM payments');
    }
}
