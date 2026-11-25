<?php

namespace Tests\Feature;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PostgresStoredProcedureIntegrationTest extends TestCase
{
    /**
     * Test the PostgreSQL stored procedure sp_generate_monthly_fees and wrapper function fn_generate_monthly_fees.
     * Note: This test requires a live isolated PostgreSQL database instance.
     */
    public function test_postgres_plpgsql_routine_execution(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'PostgreSQL PL/pgSQL stored procedure integration test requires an isolated PostgreSQL database connection. ' .
                'The default local testing environment runs on in-memory SQLite which does not support PL/pgSQL procedures. ' .
                'Set DB_CONNECTION=pgsql with an isolated test database to execute this test.'
            );
        }

        // Ensure clean schema state independent of earlier RefreshDatabase tests
        Schema::dropIfExists('monthly_fees');
        Schema::dropIfExists('room_allocations');
        Schema::dropIfExists('residents');
        DB::unprepared(file_get_contents(database_path('stayledger_monthly_fees_schema.sql')));

        // 1. Ensure test residents exist
        DB::statement("
            INSERT INTO residents (resident_code, full_name, email, status)
            VALUES 
                ('PG-RES-01', 'Postgres Resident 1', 'pg1@test.com', 'ACTIVE'),
                ('PG-RES-02', 'Postgres Resident 2', 'pg2@test.com', 'ACTIVE')
            ON CONFLICT (resident_code) DO NOTHING;
        ");

        // 2. Execute the PL/pgSQL routine
        $result = DB::select('SELECT * FROM fn_generate_monthly_fees(?, ?, ?)', ['2026-11', 18000.00, '2026-11-15']);
        $this->assertNotEmpty($result);

        $row = (array) $result[0];
        $this->assertGreaterThanOrEqual(2, $row['created_count']);
        $this->assertEquals(0, $row['skipped_count']);
        $this->assertArrayHasKey('blocked_count', $row);

        // 3. Repeated generation must skip duplicates (idempotency)
        $repeatResult = DB::select('SELECT * FROM fn_generate_monthly_fees(?, ?, ?)', ['2026-11', 18000.00, '2026-11-15']);
        $repeatRow = (array) $repeatResult[0];
        $this->assertEquals(0, $repeatRow['created_count']);
        $this->assertGreaterThanOrEqual(2, $repeatRow['skipped_count']);
        $this->assertArrayHasKey('blocked_count', $repeatRow);

        // 4. Test PL/pgSQL validation on negative amount
        $this->expectException(Exception::class);
        DB::select('SELECT * FROM fn_generate_monthly_fees(?, ?, ?)', ['2026-11', -100.00, '2026-11-15']);
    }
}