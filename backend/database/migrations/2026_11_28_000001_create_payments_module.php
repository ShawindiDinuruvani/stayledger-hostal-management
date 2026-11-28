<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $requiredColumns = [
            'payment_id',
            'payment_code',
            'fee_id',
            'amount',
            'paid_date',
            'method',
            'reference_no',
            'payment_type',
            'reverses_payment_id',
            'reason',
            'recorded_by',
            'created_at',
        ];

        if (Schema::hasTable('payments')) {
            $existingColumns = Schema::getColumnListing('payments');
            $missingColumns = array_values(array_diff($requiredColumns, $existingColumns));

            if ($missingColumns !== []) {
                if (DB::table('payments')->exists()) {
                    throw new \RuntimeException(
                        "The existing 'payments' table contains records and is missing required columns: " . implode(', ', $missingColumns) . '. Map those records to the payments module schema before rerunning this migration.'
                    );
                }

                $this->addMissingPaymentColumns($missingColumns);
            }
        }

        if (!Schema::hasTable('payments')) {
            Schema::create('payments', function (Blueprint $table) {
                $table->id('payment_id');
                $table->string('payment_code', 30)->unique();
                $table->unsignedBigInteger('fee_id');
                $table->decimal('amount', 10, 2);
                $table->date('paid_date');
                $table->string('method', 20);
                $table->string('reference_no', 100)->nullable();
                $table->string('payment_type', 20)->default('PAYMENT');
                $table->unsignedBigInteger('reverses_payment_id')->nullable()->unique();
                $table->text('reason')->nullable();
                $table->unsignedBigInteger('recorded_by')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index('fee_id', 'idx_payments_fee');
                $table->index('paid_date', 'idx_payments_paid_date');
                $table->index('payment_type', 'idx_payments_type');
                $table->foreign('fee_id')->references('fee_id')->on('monthly_fees')->restrictOnDelete();
                $table->foreign('reverses_payment_id')->references('payment_id')->on('payments')->restrictOnDelete();
                $table->foreign('recorded_by')->references('id')->on('users')->restrictOnDelete();
            });

            if (DB::getDriverName() === 'pgsql') {
                DB::statement("ALTER TABLE payments ADD CONSTRAINT chk_payment_amount_type CHECK ((payment_type = 'PAYMENT' AND amount > 0) OR (payment_type = 'REVERSAL' AND amount < 0))");
                DB::statement("ALTER TABLE payments ADD CONSTRAINT chk_payment_method CHECK (method IN ('CASH','BANK','CARD'))");
                DB::statement("ALTER TABLE payments ADD CONSTRAINT chk_payment_type CHECK (payment_type IN ('PAYMENT','REVERSAL'))");
                DB::statement("ALTER TABLE payments ADD CONSTRAINT chk_payment_paid_date CHECK (paid_date <= CURRENT_DATE)");
            } elseif (DB::getDriverName() === 'sqlite') {
                DB::unprepared("
                    CREATE VIEW IF NOT EXISTS vw_payment_history AS
                    SELECT p.payment_id,p.payment_code,p.fee_id,r.resident_id,r.resident_code,r.full_name AS resident_name,
                           rm.room_id,rm.room_number,p.amount,p.paid_date,p.method,p.payment_type,
                           p.reverses_payment_id AS reversal_reference,p.reference_no,p.reason,u.name AS recorded_user
                    FROM payments p
                    JOIN monthly_fees mf ON mf.fee_id=p.fee_id
                    JOIN residents r ON r.resident_id=mf.resident_id
                    LEFT JOIN room_allocations ra ON ra.resident_id=r.resident_id AND ra.status='Active'
                    LEFT JOIN rooms rm ON rm.room_id=ra.room_id
                    LEFT JOIN users u ON u.id=p.recorded_by
                ");
                DB::unprepared("
                    CREATE VIEW IF NOT EXISTS vw_fee_balance AS
                    SELECT mf.fee_id,r.resident_id,r.resident_code,r.full_name AS resident_name,
                           rm.room_id,rm.room_number,mf.amount_due,
                           COALESCE(SUM(p.amount),0) AS total_paid,
                           MAX(mf.amount_due-COALESCE(SUM(p.amount),0),0) AS outstanding_balance,
                           mf.status AS monthly_fee_status
                    FROM monthly_fees mf
                    JOIN residents r ON r.resident_id=mf.resident_id
                    LEFT JOIN room_allocations ra ON ra.resident_id=r.resident_id AND ra.status='Active'
                    LEFT JOIN rooms rm ON rm.room_id=ra.room_id
                    LEFT JOIN payments p ON p.fee_id=mf.fee_id
                    GROUP BY mf.fee_id,r.resident_id,r.resident_code,r.full_name,
                             rm.room_id,rm.room_number,mf.amount_due,mf.status
                ");
            }
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("
                CREATE OR REPLACE FUNCTION fn_fee_balance(p_fee_id BIGINT)
                RETURNS NUMERIC LANGUAGE sql STABLE AS $$
                    SELECT mf.amount_due - COALESCE((
                        SELECT SUM(p.amount) FROM payments p WHERE p.fee_id = p_fee_id
                    ), 0)
                    FROM monthly_fees mf
                    WHERE mf.fee_id = p_fee_id
                $$;
            ");

            DB::unprepared("
                CREATE OR REPLACE FUNCTION fn_block_payment_mutation()
                RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN
                    RAISE EXCEPTION 'Payment records are immutable and cannot be updated or deleted.';
                END;
                $$;
            ");
            DB::unprepared("DROP TRIGGER IF EXISTS trg_payments_immutable ON payments");
            DB::unprepared("
                CREATE TRIGGER trg_payments_immutable
                BEFORE UPDATE OR DELETE ON payments
                FOR EACH ROW EXECUTE FUNCTION fn_block_payment_mutation();
            ");

            DB::unprepared("
                CREATE OR REPLACE VIEW vw_payment_history AS
                SELECT p.payment_id, p.payment_code, p.fee_id,
                       r.resident_id, r.resident_code, r.full_name AS resident_name,
                       rm.room_id, rm.room_number, p.amount, p.paid_date,
                       p.method, p.payment_type, p.reference_no,
                       p.reverses_payment_id AS reversal_reference,
                       p.reason,
                       u.name AS recorded_user
                FROM payments p
                JOIN monthly_fees mf ON mf.fee_id = p.fee_id
                JOIN residents r ON r.resident_id = mf.resident_id
                LEFT JOIN room_allocations ra ON ra.resident_id = r.resident_id AND ra.status = 'Active'
                LEFT JOIN rooms rm ON rm.room_id = ra.room_id
                LEFT JOIN users u ON u.id = p.recorded_by;
            ");

            DB::unprepared("
                CREATE OR REPLACE VIEW vw_fee_balance AS
                SELECT mf.fee_id, r.resident_id, r.resident_code, r.full_name AS resident_name,
                       rm.room_id, rm.room_number, mf.amount_due,
                       COALESCE(SUM(p.amount), 0) AS total_paid,
                       GREATEST(mf.amount_due - COALESCE(SUM(p.amount), 0), 0) AS outstanding_balance,
                       mf.status AS monthly_fee_status
                FROM monthly_fees mf
                JOIN residents r ON r.resident_id = mf.resident_id
                LEFT JOIN room_allocations ra ON ra.resident_id = r.resident_id AND ra.status = 'Active'
                LEFT JOIN rooms rm ON rm.room_id = ra.room_id
                LEFT JOIN payments p ON p.fee_id = mf.fee_id
                GROUP BY mf.fee_id, r.resident_id, r.resident_code, r.full_name,
                         rm.room_id, rm.room_number, mf.amount_due, mf.status;
            ");

            DB::unprepared("
                CREATE OR REPLACE PROCEDURE sp_record_payment(
                    p_fee_id BIGINT, p_amount NUMERIC, p_paid_date DATE,
                    p_method VARCHAR, p_reference_no VARCHAR, p_recorded_by BIGINT,
                    p_reason VARCHAR DEFAULT NULL, INOUT p_payment_id BIGINT DEFAULT NULL
                )
                LANGUAGE plpgsql AS $$
                DECLARE v_fee monthly_fees%ROWTYPE; v_paid NUMERIC; v_balance NUMERIC;
                BEGIN
                    IF p_amount IS NULL OR p_amount <= 0 THEN RAISE EXCEPTION 'Payment amount must be greater than zero.'; END IF;
                    IF p_paid_date IS NULL OR p_paid_date > CURRENT_DATE THEN RAISE EXCEPTION 'Payment date cannot be in the future.'; END IF;
                    IF p_method NOT IN ('CASH','BANK','CARD') THEN RAISE EXCEPTION 'Payment method must be CASH, BANK, or CARD.'; END IF;
                    SELECT * INTO v_fee FROM monthly_fees WHERE fee_id = p_fee_id FOR UPDATE;
                    IF NOT FOUND THEN RAISE EXCEPTION 'Monthly fee not found.'; END IF;
                    IF p_paid_date < v_fee.created_at::DATE THEN RAISE EXCEPTION 'Payment date cannot be earlier than the fee creation date.'; END IF;
                    IF v_fee.status = 'PAID' THEN RAISE EXCEPTION 'A PAID fee cannot accept additional payments.'; END IF;
                    SELECT COALESCE(SUM(amount), 0) INTO v_paid FROM payments WHERE fee_id = p_fee_id;
                    v_balance := v_fee.amount_due - v_paid;
                    IF p_amount > v_balance THEN RAISE EXCEPTION 'Payment amount exceeds the outstanding balance.'; END IF;
                    INSERT INTO payments(payment_code, fee_id, amount, paid_date, method, reference_no, payment_type, recorded_by)
                    VALUES ('PAY-' || TO_CHAR(CURRENT_TIMESTAMP,'YYYYMMDD') || '-' || LPAD(NEXTVAL('payments_payment_id_seq')::TEXT,8,'0'),
                            p_fee_id,p_amount,p_paid_date,p_method,p_reference_no,'PAYMENT',p_recorded_by)
                    RETURNING payment_id INTO p_payment_id;
                    SELECT COALESCE(SUM(amount),0) INTO v_paid FROM payments WHERE fee_id=p_fee_id;
                    UPDATE monthly_fees SET status = CASE WHEN v_paid >= amount_due THEN 'PAID' ELSE 'PARTIALLY_PAID' END,
                                           updated_at=CURRENT_TIMESTAMP WHERE fee_id=p_fee_id;
                END;
                $$;
            ");

            DB::unprepared("
                CREATE OR REPLACE PROCEDURE sp_reverse_payment(
                    p_payment_id BIGINT, p_recorded_by BIGINT, p_reason TEXT,
                    INOUT p_reversal_id BIGINT DEFAULT NULL
                )
                LANGUAGE plpgsql AS $$
                DECLARE v_original payments%ROWTYPE; v_fee monthly_fees%ROWTYPE; v_paid NUMERIC;
                BEGIN
                    IF p_reason IS NULL OR BTRIM(p_reason)='' THEN RAISE EXCEPTION 'A reversal reason is required.'; END IF;
                    SELECT * INTO v_original FROM payments WHERE payment_id=p_payment_id FOR UPDATE;
                    IF NOT FOUND THEN RAISE EXCEPTION 'Payment not found.'; END IF;
                    IF v_original.payment_type='REVERSAL' THEN RAISE EXCEPTION 'A reversal record cannot be reversed.'; END IF;
                    IF EXISTS(SELECT 1 FROM payments WHERE reverses_payment_id=p_payment_id) THEN RAISE EXCEPTION 'This payment has already been reversed.'; END IF;
                    SELECT * INTO v_fee FROM monthly_fees WHERE fee_id=v_original.fee_id FOR UPDATE;
                    IF NOT FOUND THEN RAISE EXCEPTION 'Monthly fee not found.'; END IF;
                    SELECT COALESCE(SUM(amount),0) INTO v_paid FROM payments WHERE fee_id=v_original.fee_id;
                    IF v_paid + (-v_original.amount) < 0 THEN RAISE EXCEPTION 'Reversal cannot make the total paid amount negative.'; END IF;
                    INSERT INTO payments(payment_code,fee_id,amount,paid_date,method,reference_no,payment_type,reverses_payment_id,reason,recorded_by)
                    VALUES ('REV-'||TO_CHAR(CURRENT_TIMESTAMP,'YYYYMMDD')||'-'||LPAD(NEXTVAL('payments_payment_id_seq')::TEXT,8,'0'),
                            v_original.fee_id,-v_original.amount,v_original.paid_date,v_original.method,v_original.reference_no,'REVERSAL',
                            v_original.payment_id,p_reason,p_recorded_by)
                    RETURNING payment_id INTO p_reversal_id;
                    SELECT COALESCE(SUM(amount),0) INTO v_paid FROM payments WHERE fee_id=v_original.fee_id;
                    UPDATE monthly_fees SET status=CASE WHEN v_paid>=amount_due THEN 'PAID' WHEN v_paid>0 THEN 'PARTIALLY_PAID' ELSE 'UNPAID' END,
                                           updated_at=CURRENT_TIMESTAMP WHERE fee_id=v_original.fee_id;
                END;
                $$;
            ");
        }
    }

    private function addMissingPaymentColumns(array $missingColumns): void
    {
        $isPostgres = DB::getDriverName() === 'pgsql';
        $definitions = $isPostgres
            ? [
                'payment_id' => 'BIGINT GENERATED BY DEFAULT AS IDENTITY',
                'payment_code' => 'VARCHAR(30) NOT NULL',
                'fee_id' => 'BIGINT NOT NULL',
                'amount' => 'NUMERIC(10, 2) NOT NULL',
                'paid_date' => 'DATE NOT NULL',
                'method' => 'VARCHAR(20) NOT NULL',
                'reference_no' => 'VARCHAR(100)',
                'payment_type' => "VARCHAR(20) NOT NULL DEFAULT 'PAYMENT'",
                'reverses_payment_id' => 'BIGINT',
                'reason' => 'TEXT',
                'recorded_by' => 'BIGINT',
                'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            ]
            : [
                'payment_id' => 'INTEGER',
                'payment_code' => 'VARCHAR(30)',
                'fee_id' => 'INTEGER',
                'amount' => 'NUMERIC(10, 2)',
                'paid_date' => 'DATE',
                'method' => 'VARCHAR(20)',
                'reference_no' => 'VARCHAR(100)',
                'payment_type' => "VARCHAR(20) DEFAULT 'PAYMENT'",
                'reverses_payment_id' => 'INTEGER',
                'reason' => 'TEXT',
                'recorded_by' => 'INTEGER',
                'created_at' => 'DATETIME',
            ];

        foreach ($missingColumns as $column) {
            DB::statement("ALTER TABLE payments ADD COLUMN {$column} {$definitions[$column]}");
        }

        if (!$isPostgres) {
            return;
        }

        $constraints = [
            'payment_id' => 'ALTER TABLE payments ADD CONSTRAINT payments_payment_id_unique UNIQUE (payment_id)',
            'payment_code' => 'ALTER TABLE payments ADD CONSTRAINT payments_payment_code_unique UNIQUE (payment_code)',
            'fee_id' => 'ALTER TABLE payments ADD CONSTRAINT payments_fee_id_foreign FOREIGN KEY (fee_id) REFERENCES monthly_fees(fee_id) ON DELETE RESTRICT',
            'reverses_payment_id' => 'ALTER TABLE payments ADD CONSTRAINT payments_reverses_payment_id_unique UNIQUE (reverses_payment_id), ADD CONSTRAINT payments_reverses_payment_id_foreign FOREIGN KEY (reverses_payment_id) REFERENCES payments(payment_id) ON DELETE RESTRICT',
            'recorded_by' => 'ALTER TABLE payments ADD CONSTRAINT payments_recorded_by_foreign FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE RESTRICT',
        ];

        foreach ($constraints as $column => $statement) {
            if (in_array($column, $missingColumns, true)) {
                DB::statement($statement);
            }
        }

        foreach ([
            'fee_id' => 'idx_payments_fee',
            'paid_date' => 'idx_payments_paid_date',
            'payment_type' => 'idx_payments_type',
        ] as $column => $index) {
            if (in_array($column, $missingColumns, true)) {
                DB::statement("CREATE INDEX IF NOT EXISTS {$index} ON payments ({$column})");
            }
        }

        DB::unprepared("
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'chk_payment_amount_type' AND conrelid = 'payments'::regclass) THEN
                    ALTER TABLE payments ADD CONSTRAINT chk_payment_amount_type CHECK ((payment_type = 'PAYMENT' AND amount > 0) OR (payment_type = 'REVERSAL' AND amount < 0));
                END IF;
                IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'chk_payment_method' AND conrelid = 'payments'::regclass) THEN
                    ALTER TABLE payments ADD CONSTRAINT chk_payment_method CHECK (method IN ('CASH','BANK','CARD'));
                END IF;
                IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'chk_payment_type' AND conrelid = 'payments'::regclass) THEN
                    ALTER TABLE payments ADD CONSTRAINT chk_payment_type CHECK (payment_type IN ('PAYMENT','REVERSAL'));
                END IF;
                IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'chk_payment_paid_date' AND conrelid = 'payments'::regclass) THEN
                    ALTER TABLE payments ADD CONSTRAINT chk_payment_paid_date CHECK (paid_date <= CURRENT_DATE);
                END IF;
            END;
            $$;
        ");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("DROP VIEW IF EXISTS vw_fee_balance");
            DB::unprepared("DROP VIEW IF EXISTS vw_payment_history");
            DB::unprepared("DROP PROCEDURE IF EXISTS sp_reverse_payment(BIGINT,BIGINT,TEXT,BIGINT)");
            DB::unprepared("DROP PROCEDURE IF EXISTS sp_record_payment(BIGINT,NUMERIC,DATE,VARCHAR,VARCHAR,BIGINT,VARCHAR,BIGINT)");
            DB::unprepared("DROP TRIGGER IF EXISTS trg_payments_immutable ON payments");
            DB::unprepared("DROP FUNCTION IF EXISTS fn_block_payment_mutation()");
            DB::unprepared("DROP FUNCTION IF EXISTS fn_fee_balance(BIGINT)");
        }
        Schema::dropIfExists('payments');
    }
};
