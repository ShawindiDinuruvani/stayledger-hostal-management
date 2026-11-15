<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Installs PostgreSQL / PL/pgSQL Stored Procedure and Function for Monthly Fee Generation.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // 1. PostgreSQL Stored Procedure
            DB::unprepared("
                CREATE OR REPLACE PROCEDURE sp_generate_monthly_fees(
                    p_billing_period VARCHAR,
                    p_amount NUMERIC,
                    p_due_date DATE,
                    INOUT p_created_count INT DEFAULT 0,
                    INOUT p_skipped_count INT DEFAULT 0,
                    INOUT p_total_eligible INT DEFAULT 0,
                    INOUT p_blocked_count INT DEFAULT 0
                )
                LANGUAGE plpgsql
                AS $$
                DECLARE
                    v_resident RECORD;
                    v_fee_code VARCHAR(30);
                    v_inserted_id INT;
                    v_period_clean VARCHAR(6);
                    v_period_start DATE;
                    v_period_end DATE;
                    v_has_allocations BOOLEAN := FALSE;
                BEGIN
                    -- Validate billing period format (YYYY-MM)
                    IF p_billing_period !~ '^\\d{4}-(0[1-9]|1[0-2])$' THEN
                        RAISE EXCEPTION 'Invalid billing period format. Expected YYYY-MM, received %', p_billing_period;
                    END IF;

                    -- Validate fee amount
                    IF p_amount IS NULL OR p_amount <= 0 THEN
                        RAISE EXCEPTION 'Fee amount must be greater than zero. Received %', p_amount;
                    END IF;

                    -- Validate due date
                    IF p_due_date IS NULL THEN
                        RAISE EXCEPTION 'Due date cannot be null.';
                    END IF;

                    v_period_start := TO_DATE(p_billing_period || '-01', 'YYYY-MM-DD');
                    v_period_end := (v_period_start + INTERVAL '1 month - 1 day')::DATE;
                    v_period_clean := REPLACE(p_billing_period, '-', '');

                    p_created_count := 0;
                    p_skipped_count := 0;
                    p_total_eligible := 0;
                    p_blocked_count := 0;

                    -- Check if room_allocations table exists
                    SELECT EXISTS (
                        SELECT 1 FROM information_schema.tables 
                        WHERE table_name = 'room_allocations'
                    ) INTO v_has_allocations;

                    IF v_has_allocations THEN
                        -- Evaluate residents who had stays during this billing period
                        -- Historical eligibility: does NOT require residents.status = 'ACTIVE'
                        FOR v_resident IN 
                            SELECT 
                                r.resident_id,
                                r.resident_code,
                                r.full_name,
                                MIN(ra.start_date) AS min_start,
                                MAX(CASE WHEN ra.end_date IS NULL THEN DATE '9999-12-31' ELSE ra.end_date END) AS max_end
                            FROM residents r
                            JOIN room_allocations ra ON r.resident_id = ra.resident_id
                            WHERE ra.start_date <= v_period_end
                              AND (ra.end_date IS NULL OR ra.end_date >= v_period_start)
                            GROUP BY r.resident_id, r.resident_code, r.full_name
                            ORDER BY r.resident_id ASC
                        LOOP
                            -- Partial-month stay detection:
                            -- Full month requires min_start <= period_start AND max_end >= period_end
                            IF v_resident.min_start > v_period_start OR v_resident.max_end < v_period_end THEN
                                -- Blocked server-side pending confirmed hostel prorating policy
                                p_blocked_count := p_blocked_count + 1;
                                CONTINUE;
                            END IF;

                            -- Full-month stay: eligible
                            p_total_eligible := p_total_eligible + 1;

                            IF EXISTS (
                                SELECT 1 FROM monthly_fees 
                                WHERE resident_id = v_resident.resident_id 
                                  AND billing_period = p_billing_period
                            ) THEN
                                p_skipped_count := p_skipped_count + 1;
                            ELSE
                                v_fee_code := 'FEE-' || v_period_clean || '-' || LPAD(v_resident.resident_id::TEXT, 4, '0');

                                INSERT INTO monthly_fees (
                                    fee_code, resident_id, billing_period, amount_due, due_date, status, remarks, created_at, updated_at
                                ) VALUES (
                                    v_fee_code, v_resident.resident_id, p_billing_period, p_amount, p_due_date, 'UNPAID',
                                    'System generated fee for ' || p_billing_period, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                                )
                                ON CONFLICT (resident_id, billing_period) DO NOTHING
                                RETURNING fee_id INTO v_inserted_id;

                                IF v_inserted_id IS NOT NULL THEN
                                    p_created_count := p_created_count + 1;
                                ELSE
                                    p_skipped_count := p_skipped_count + 1;
                                END IF;
                            END IF;
                        END LOOP;
                    ELSE
                        -- Fallback when room_allocations is not present: use active residents
                        FOR v_resident IN 
                            SELECT resident_id, resident_code, full_name
                            FROM residents
                            WHERE status = 'ACTIVE'
                            ORDER BY resident_id ASC
                        LOOP
                            p_total_eligible := p_total_eligible + 1;

                            IF EXISTS (
                                SELECT 1 FROM monthly_fees 
                                WHERE resident_id = v_resident.resident_id 
                                  AND billing_period = p_billing_period
                            ) THEN
                                p_skipped_count := p_skipped_count + 1;
                            ELSE
                                v_fee_code := 'FEE-' || v_period_clean || '-' || LPAD(v_resident.resident_id::TEXT, 4, '0');

                                INSERT INTO monthly_fees (
                                    fee_code, resident_id, billing_period, amount_due, due_date, status, remarks, created_at, updated_at
                                ) VALUES (
                                    v_fee_code, v_resident.resident_id, p_billing_period, p_amount, p_due_date, 'UNPAID',
                                    'System generated fee for ' || p_billing_period, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                                )
                                ON CONFLICT (resident_id, billing_period) DO NOTHING
                                RETURNING fee_id INTO v_inserted_id;

                                IF v_inserted_id IS NOT NULL THEN
                                    p_created_count := p_created_count + 1;
                                ELSE
                                    p_skipped_count := p_skipped_count + 1;
                                END IF;
                            END IF;
                        END LOOP;
                    END IF;
                END;
                $$;
            ");

            // 2. Helper Wrapper Function returning TABLE for SELECT queries
            DB::unprepared("
                CREATE OR REPLACE FUNCTION fn_generate_monthly_fees(
                    p_billing_period VARCHAR,
                    p_amount NUMERIC,
                    p_due_date DATE
                )
                RETURNS TABLE (
                    created_count INT,
                    skipped_count INT,
                    total_eligible INT,
                    blocked_count INT
                )
                LANGUAGE plpgsql
                AS $$
                DECLARE
                    v_created INT := 0;
                    v_skipped INT := 0;
                    v_total INT := 0;
                    v_blocked INT := 0;
                BEGIN
                    CALL sp_generate_monthly_fees(p_billing_period, p_amount, p_due_date, v_created, v_skipped, v_total, v_blocked);
                    created_count := v_created;
                    skipped_count := v_skipped;
                    total_eligible := v_total;
                    blocked_count := v_blocked;
                    RETURN NEXT;
                END;
                $$;
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("DROP FUNCTION IF EXISTS fn_generate_monthly_fees(VARCHAR, NUMERIC, DATE);");
            DB::unprepared("DROP PROCEDURE IF EXISTS sp_generate_monthly_fees(VARCHAR, NUMERIC, DATE, INT, INT, INT, INT);");
        }
    }
};
