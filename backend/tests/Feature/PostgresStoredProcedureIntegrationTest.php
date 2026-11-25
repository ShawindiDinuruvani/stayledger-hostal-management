<?php

namespace Tests\Feature;

use Exception;
use Illuminate\Support\Facades\DB;
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
        DB::statement('DROP VIEW IF EXISTS vw_monthly_fee_summary CASCADE');
        DB::statement('DROP TABLE IF EXISTS monthly_fees CASCADE');
        DB::statement('DROP TABLE IF EXISTS room_allocations CASCADE');
        DB::statement('DROP TABLE IF EXISTS rooms CASCADE');
        DB::statement('DROP TABLE IF EXISTS floors CASCADE');
        DB::statement('DROP TABLE IF EXISTS residents CASCADE');

        DB::statement('CREATE TABLE floors (floor_id SERIAL PRIMARY KEY, floor_name VARCHAR(50) UNIQUE)');
        DB::statement('CREATE TABLE rooms (room_id SERIAL PRIMARY KEY, room_number VARCHAR(10) UNIQUE, floor_id INT)');
        DB::statement("CREATE TABLE residents (resident_id SERIAL PRIMARY KEY, resident_code VARCHAR(20) UNIQUE, full_name VARCHAR(100), email VARCHAR(100) UNIQUE, phone VARCHAR(20), status VARCHAR(20) DEFAULT 'ACTIVE', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
        DB::statement("CREATE TABLE room_allocations (allocation_id SERIAL PRIMARY KEY, allocation_code VARCHAR(20) UNIQUE, resident_id INT, room_id INT, start_date DATE, end_date DATE NULL, status VARCHAR(20) DEFAULT 'Active')");

        DB::unprepared(file_get_contents(database_path('stayledger_monthly_fees_schema.sql')));

        // 1. Seed reference floor, room, test residents, and full-month allocations
        DB::statement("INSERT INTO floors (floor_id, floor_name) VALUES (1, 'PG Test Floor')");
        DB::statement("INSERT INTO rooms (room_id, room_number, floor_id) VALUES (1, 'PG-101', 1)");
        DB::statement("
            INSERT INTO residents (resident_id, resident_code, full_name, email, status)
            VALUES 
                (1, 'PG-RES-01', 'Postgres Resident 1', 'pg1@test.com', 'ACTIVE'),
                (2, 'PG-RES-02', 'Postgres Resident 2', 'pg2@test.com', 'ACTIVE')
            ON CONFLICT (resident_code) DO NOTHING;
        ");
        DB::statement("
            INSERT INTO room_allocations (allocation_code, resident_id, room_id, start_date, end_date, status)
            VALUES
                ('PG-ALLOC-01', 1, 1, '2026-10-01', NULL, 'Active'),
                ('PG-ALLOC-02', 2, 1, '2026-10-01', NULL, 'Active');
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