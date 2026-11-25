<?php

/**
 * StayLedger - Real PostgreSQL Integration Test Runner
 * 
 * Target: Isolated PostgreSQL 17 test database (port 5433)
 * Module: Monthly Fee Management
 * Student: S. Dinuruvani (Student ID: 36750)
 * 
 * This script runs against an explicitly isolated local PostgreSQL instance.
 * It NEVER connects to or mutates the shared Neon database.
 */

$host = '127.0.0.1';
$port = 5433;
$user = 'postgres';
$pass = '';

$testsRun = 0;
$testsPassed = 0;
$testsFailed = 0;

function assertTest($description, $condition, $details = '') {
    global $testsRun, $testsPassed, $testsFailed;
    $testsRun++;
    if ($condition) {
        echo "[PASS] {$description}\n";
        $testsPassed++;
    } else {
        echo "[FAIL] {$description}: {$details}\n";
        $testsFailed++;
    }
}

try {
    echo "====================================================================\n";
    echo "STAYLEDGER - REAL POSTGRESQL ISOLATED INTEGRATION TEST SUITE\n";
    echo "Database: PostgreSQL 17 on 127.0.0.1:5433 (ISOLATED LOCAL INSTANCE)\n";
    echo "====================================================================\n\n";

    // 1. Connect to default postgres DB and recreate stayledger_isolated_test
    $pdoAdmin = new PDO("pgsql:host={$host};port={$port};dbname=postgres", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    $pdoAdmin->exec("DROP DATABASE IF EXISTS stayledger_isolated_test;");
    $pdoAdmin->exec("CREATE DATABASE stayledger_isolated_test;");
    echo "Created fresh isolated test database: stayledger_isolated_test\n\n";

    // 2. Connect to the isolated test database
    $pdo = new PDO("pgsql:host={$host};port={$port};dbname=stayledger_isolated_test", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // 3. Verify and apply prerequisite schema dependencies (Nimsara's allocations schema)
    $allocSqlPath = __DIR__ . '/../../backend/database/stayledger_allocations_schema.sql';
    if (!file_exists($allocSqlPath)) {
        throw new Exception("Prerequisite schema file not found at {$allocSqlPath}");
    }
    $allocSql = file_get_contents($allocSqlPath);
    $pdo->exec($allocSql);
    echo "Applied teammate prerequisite schema (floors, rooms, residents, room_allocations).\n";

    // 4. Verify prerequisite tables exist
    $tableCount = (int) $pdo->query("
        SELECT COUNT(*) FROM information_schema.tables 
        WHERE table_schema = 'public' AND table_name IN ('floors', 'rooms', 'residents', 'room_allocations')
    ")->fetchColumn();
    assertTest("Prerequisite schema dependencies exist in isolated database", $tableCount === 4);

    $residentCount = (int) $pdo->query("SELECT COUNT(*) FROM residents WHERE status = 'ACTIVE'")->fetchColumn();
    assertTest("Initial active residents populated", $residentCount === 10, "Found {$residentCount} active residents");

    // 5. Apply Monthly Fee Management schema (S. Dinuruvani's DDL)
    $feeSqlPath = __DIR__ . '/../../backend/database/stayledger_monthly_fees_schema.sql';
    if (!file_exists($feeSqlPath)) {
        throw new Exception("Monthly fee schema file not found at {$feeSqlPath}");
    }
    $feeSql = file_get_contents($feeSqlPath);
    $pdo->exec($feeSql);
    echo "Applied monthly fees schema (table, constraints, view, sp_generate_monthly_fees, fn_generate_monthly_fees).\n\n";

    // --- TEST SUITE EXECUTION ---

    // TEST 1: Stored Procedure & Function Invocation
    $stmt = $pdo->prepare("SELECT * FROM fn_generate_monthly_fees('2026-11', 18000.00, '2026-11-15')");
    $stmt->execute();
    $result1 = $stmt->fetch();

    assertTest(
        "PL/pgSQL procedure generates fee records for all eligible active residents",
        $result1['created_count'] === 10 && $result1['skipped_count'] === 0 && $result1['total_eligible'] === 10,
        "Created: {$result1['created_count']}, Skipped: {$result1['skipped_count']}, Eligible: {$result1['total_eligible']}"
    );

    $totalInDb = (int) $pdo->query("SELECT COUNT(*) FROM monthly_fees WHERE billing_period = '2026-11'")->fetchColumn();
    assertTest("Persisted fee records count matches created count (10 rows)", $totalInDb === 10);

    // TEST 2: Idempotent Repeated Generation (Duplicate Prevention)
    $stmt->execute();
    $result2 = $stmt->fetch();
    assertTest(
        "Repeated generation creates 0 duplicates and reports all 10 as skipped",
        $result2['created_count'] === 0 && $result2['skipped_count'] === 10 && $result2['total_eligible'] === 10,
        "Created: {$result2['created_count']}, Skipped: {$result2['skipped_count']}"
    );

    $totalInDbAfter = (int) $pdo->query("SELECT COUNT(*) FROM monthly_fees WHERE billing_period = '2026-11'")->fetchColumn();
    assertTest("Total rows in monthly_fees remains exactly 10 after repeated run", $totalInDbAfter === 10);

    // TEST 3: Database UNIQUE constraint enforcement
    try {
        $pdo->exec("
            INSERT INTO monthly_fees (fee_code, resident_id, billing_period, amount_due, due_date)
            VALUES ('FEE-DUP-TEST', 1, '2026-11', 18000.00, '2026-11-15')
        ");
        assertTest("Database enforces UNIQUE (resident_id, billing_period)", false, "Duplicate insert unexpectedly succeeded");
    } catch (PDOException $e) {
        $isUniqueViolation = strpos($e->getMessage(), 'uq_resident_billing_period') !== false || $e->getCode() === '23505';
        assertTest("Database enforces UNIQUE (resident_id, billing_period) constraint violation", $isUniqueViolation);
    }

    // TEST 4: ON CONFLICT DO NOTHING Concurrency Safety
    $conflictStmt = $pdo->prepare("
        INSERT INTO monthly_fees (fee_code, resident_id, billing_period, amount_due, due_date)
        VALUES ('FEE-CONFLICT-TEST', 1, '2026-11', 18000.00, '2026-11-15')
        ON CONFLICT (resident_id, billing_period) DO NOTHING
    ");
    $conflictStmt->execute();
    assertTest("ON CONFLICT DO NOTHING handles duplicate resident/period safely without exception", $conflictStmt->rowCount() === 0);

    // TEST 5: PL/pgSQL Parameter Validation (Negative Amount Rejection)
    try {
        $pdo->query("SELECT * FROM fn_generate_monthly_fees('2026-11', -500.00, '2026-11-15')");
        assertTest("PL/pgSQL procedure rejects negative fee amount", false, "Negative amount was accepted");
    } catch (PDOException $e) {
        $hasMessage = strpos($e->getMessage(), 'Fee amount must be greater than zero') !== false;
        assertTest("PL/pgSQL procedure rejects negative fee amount with exception", $hasMessage);
    }

    // TEST 6: PL/pgSQL Parameter Validation (Invalid Period Format Rejection)
    try {
        $pdo->query("SELECT * FROM fn_generate_monthly_fees('2026-13', 18000.00, '2026-11-15')");
        assertTest("PL/pgSQL procedure rejects invalid billing period format (2026-13)", false, "Invalid period was accepted");
    } catch (PDOException $e) {
        $hasMessage = strpos($e->getMessage(), 'Invalid billing period format') !== false;
        assertTest("PL/pgSQL procedure rejects invalid billing period with format exception", $hasMessage);
    }

    // TEST 7: Transaction Rollback on Failure
    $pdo->beginTransaction();
    $pdo->exec("
        INSERT INTO monthly_fees (fee_code, resident_id, billing_period, amount_due, due_date)
        VALUES ('FEE-ROLLBACK-TEST', 1, '2027-01', 18000.00, '2027-01-15')
    ");
    $pdo->rollBack();

    $rollbackCount = (int) $pdo->query("SELECT COUNT(*) FROM monthly_fees WHERE fee_code = 'FEE-ROLLBACK-TEST'")->fetchColumn();
    assertTest("Transaction rollback correctly discards uncommitted fee records", $rollbackCount === 0);

    // TEST 8: Historical Eligibility Boundary (Inactive Residents Must Not Be Billed)
    $pdo->exec("
        INSERT INTO residents (resident_code, full_name, email, status)
        VALUES ('RES-INACTIVE', 'Archived Resident', 'inactive@test.com', 'INACTIVE');
    ");
    $stmtHist = $pdo->prepare("SELECT * FROM fn_generate_monthly_fees('2026-12', 18000.00, '2026-12-15')");
    $stmtHist->execute();
    $resHist = $stmtHist->fetch();

    $inactiveBilled = (int) $pdo->query("
        SELECT COUNT(*) FROM monthly_fees mf
        JOIN residents r ON mf.resident_id = r.resident_id
        WHERE r.status = 'INACTIVE' AND mf.billing_period = '2026-12'
    ")->fetchColumn();

    assertTest("Historical boundary: INACTIVE residents are excluded from billing generation", $inactiveBilled === 0);

    echo "\n====================================================================\n";
    echo "REAL POSTGRESQL TEST SUMMARY: {$testsPassed} / {$testsRun} PASSED";
    if ($testsFailed > 0) {
        echo " ({$testsFailed} FAILED)\n";
        exit(1);
    } else {
        echo " (ALL PASSED)\n";
        echo "====================================================================\n";
    }

} catch (Exception $e) {
    echo "\n[ERROR] Isolated PostgreSQL test execution failed: " . $e->getMessage() . "\n";
    exit(1);
}
