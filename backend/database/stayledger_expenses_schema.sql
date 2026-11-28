-- ============================================================================
-- STAYLEDGER ADBMS - POSTGRESQL (NEON) SCHEMA
-- MODULE: HOSTEL EXPENSES
-- ============================================================================

CREATE TABLE IF NOT EXISTS hostel_expenses (
    expense_id SERIAL PRIMARY KEY,
    description VARCHAR(255) NOT NULL,
    category VARCHAR(100) NOT NULL,
    facility VARCHAR(100) NOT NULL,
    amount NUMERIC(12, 2) NOT NULL CHECK (amount > 0),
    expense_date DATE NOT NULL DEFAULT CURRENT_DATE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_hostel_expenses_date
    ON hostel_expenses (expense_date DESC);
