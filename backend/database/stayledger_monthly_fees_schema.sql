-- ============================================================================
-- STAYLEDGER ADBMS - STANDALONE SQL REFERENCE SCHEMA
-- MODULE: MONTHLY FEE MANAGEMENT
-- Member: S. Dinuruvani (Student ID: 36750)
-- University Module: CS304.3 Advanced Database Management Systems
--
-- PURPOSE & ARCHITECTURAL NOTE:
-- - The primary runtime source of truth for Monthly Fee Management database
--   schema and stored procedures is in Laravel migrations:
--     backend-laravel/database/migrations/2026_11_15_000001_create_monthly_fees_table.php
--     backend-laravel/database/migrations/2026_11_15_000002_create_monthly_fee_generation_routine.php
-- - This file is maintained as a standalone SQL reference for direct PostgreSQL /
--   ADBMS grading, manual psql inspection, and reference execution without the Laravel CLI.
-- ============================================================================

-- 1. Create monthly_fees Table
CREATE TABLE IF NOT EXISTS monthly_fees (
    fee_id SERIAL PRIMARY KEY,
    fee_code VARCHAR(30) NOT NULL UNIQUE,
    resident_id INT NOT NULL REFERENCES residents(resident_id) ON DELETE RESTRICT,
    billing_period VARCHAR(7) NOT NULL, -- Format: YYYY-MM (e.g., '2026-11')
    amount_due NUMERIC(10, 2) NOT NULL CHECK (amount_due > 0),
    due_date DATE NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'UNPAID' CHECK (status IN ('UNPAID', 'PARTIALLY_PAID', 'PAID')),
    remarks VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_resident_billing_period UNIQUE (resident_id, billing_period),
    CONSTRAINT chk_billing_period_fmt CHECK (billing_period ~ '^\d{4}-(0[1-9]|1[0-2])$')
);

-- 2. Performance Indexes
CREATE INDEX IF NOT EXISTS idx_monthly_fees_period ON monthly_fees (billing_period);
CREATE INDEX IF NOT EXISTS idx_monthly_fees_resident ON monthly_fees (resident_id);
CREATE INDEX IF NOT EXISTS idx_monthly_fees_status ON monthly_fees (status);
CREATE INDEX IF NOT EXISTS idx_monthly_fees_created_at ON monthly_fees (created_at DESC);

-- 3. Live Monthly Fee Summary View (vw_monthly_fee_summary)
-- Combines fee details, resident details, room allocations, and payment integration if payments exist
CREATE OR REPLACE VIEW vw_monthly_fee_summary AS
SELECT 
    mf.fee_id,
    mf.fee_code,
    mf.billing_period,
    mf.amount_due,
    mf.due_date,
    mf.status AS fee_status,
    mf.remarks,
    mf.created_at,
    mf.updated_at,
    r.resident_id,
    r.resident_code,
    r.full_name AS resident_name,
    r.email AS resident_email,
    r.phone AS resident_phone,
    r.status AS resident_status,
    rm.room_id,
    rm.room_number,
    fl.floor_id,
    fl.floor_name
FROM monthly_fees mf
INNER JOIN residents r ON mf.resident_id = r.resident_id
LEFT JOIN LATERAL (
    SELECT ra.room_id
    FROM room_allocations ra
    WHERE ra.resident_id = r.resident_id
      AND ra.status = 'Active'
    ORDER BY ra.allocation_id DESC
    LIMIT 1
) cur_ra ON TRUE
LEFT JOIN rooms rm ON cur_ra.room_id = rm.room_id
LEFT JOIN floors fl ON rm.floor_id = fl.floor_id;

-- 4. Advanced Database Routine: PostgreSQL Stored Procedure for Fee Generation
-- Inputs:
--   p_billing_period: 'YYYY-MM'
--   p_amount: NUMERIC(10, 2)
--   p_due_date: DATE
-- Outputs:
--   p_created_count: Number of fee records created
--   p_skipped_count: Number of eligible residents skipped because they already had a record
--   p_total_eligible: Total number of active residents eligible for billing
--   p_blocked_count: Number of residents blocked due to partial-month stays pending prorating policy
--
-- Concurrency & Transaction Safety:
--   - Does NOT issue internal COMMIT or ROLLBACK so that application-level transaction management
--     in PHP PDO maintains full boundary ownership.
--   - Uses ON CONFLICT (resident_id, billing_period) DO NOTHING to safely handle concurrent runs.
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
    IF p_billing_period !~ '^\d{4}-(0[1-9]|1[0-2])$' THEN
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

    -- Initialize counters
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

            -- Check if fee record already exists for this resident and billing period
            IF EXISTS (
                SELECT 1 FROM monthly_fees 
                WHERE resident_id = v_resident.resident_id 
                  AND billing_period = p_billing_period
            ) THEN
                p_skipped_count := p_skipped_count + 1;
            ELSE
                v_fee_code := 'FEE-' || v_period_clean || '-' || LPAD(v_resident.resident_id::TEXT, 4, '0');

                INSERT INTO monthly_fees (
                    fee_code,
                    resident_id,
                    billing_period,
                    amount_due,
                    due_date,
                    status,
                    remarks
                ) VALUES (
                    v_fee_code,
                    v_resident.resident_id,
                    p_billing_period,
                    p_amount,
                    p_due_date,
                    'UNPAID',
                    'System generated fee for ' || p_billing_period
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
                    fee_code,
                    resident_id,
                    billing_period,
                    amount_due,
                    due_date,
                    status,
                    remarks
                ) VALUES (
                    v_fee_code,
                    v_resident.resident_id,
                    p_billing_period,
                    p_amount,
                    p_due_date,
                    'UNPAID',
                    'System generated fee for ' || p_billing_period
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

-- 5. Helper Function Wrapper for Environments/PDO Drivers Querying via SELECT
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
