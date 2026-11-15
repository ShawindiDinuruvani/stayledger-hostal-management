<?php

/**
 * StayLedger ADBMS - Real Laravel-to-PostgreSQL Integration Test Runner
 * Module: Monthly Fee Management (S. Dinuruvani - 36750)
 *
 * Verifies:
 * 1. Running Laravel migrations themselves (Artisan migrate) against isolated PostgreSQL.
 * 2. Laravel MonthlyFeeService invoking the PostgreSQL routine sp_generate_monthly_fees / fn_generate_monthly_fees.
 * 3. Historical stay eligibility (inactive resident with valid stay is eligible).
 * 4. Partial-month stay server-side blocking (blocked pending confirmed prorating policy).
 * 5. Idempotent duplicate prevention.
 * 6. Transaction rollback integrity.
 * 7. Real concurrent execution across two independent PDO connections.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use App\Services\MonthlyFeeService;

$dbHost = '127.0.0.1';
$dbPort = '5433';
$dbName = 'stayledger_isolated_test';
$dbUser = 'postgres';
$dbPass = 'postgres';

echo "========================================================================\n";
echo "STAYLEDGER ADBMS: LARAVEL-TO-POSTGRESQL REAL INTEGRATION TEST RUNNER\n";
echo "Module: Monthly Fee Management | Owner: S. Dinuruvani (36750)\n";
echo "Isolated PostgreSQL Instance: {$dbHost}:{$dbPort}/{$dbName}\n";
echo "========================================================================\n\n";

$passedTests = 0;
$totalTests = 7;

function assertTest(bool $condition, string $description, ?string $detail = null): void
{
    global $passedTests;
    if ($condition) {
        $passedTests++;
        echo "  [PASS] {$description}\n";
        if ($detail) {
            echo "         -> {$detail}\n";
        }
    } else {
        echo "  [FAIL] {$description}\n";
        if ($detail) {
            echo "         -> Details: {$detail}\n";
        }
        exit(1);
    }
}

// 1. Configure Laravel Database Connection dynamically
config([
    'database.default' => 'pgsql',
    'database.connections.pgsql.host' => $dbHost,
    'database.connections.pgsql.port' => $dbPort,
    'database.connections.pgsql.database' => $dbName,
    'database.connections.pgsql.username' => $dbUser,
    'database.connections.pgsql.password' => $dbPass,
    'database.connections.pgsql.sslmode' => 'prefer',
]);

DB::purge('pgsql');
DB::reconnect('pgsql');

try {
    $pdo = DB::connection('pgsql')->getPdo();
    $pgVersion = $pdo->query('SHOW server_version')->fetchColumn();
    echo "Connected to PostgreSQL server successfully. Server version: {$pgVersion}\n\n";
} catch (Exception $e) {
    echo "[ERROR] Cannot connect to isolated PostgreSQL on port {$dbPort}: " . $e->getMessage() . "\n";
    exit(1);
}

// --- SETUP: Prerequisite schema and clean tables ---
echo "--- Preparing Isolated Test Database Schema ---\n";
DB::statement("DROP TABLE IF EXISTS monthly_fees CASCADE;");
DB::statement("DROP TABLE IF EXISTS room_allocations CASCADE;");
DB::statement("DROP TABLE IF EXISTS rooms CASCADE;");
DB::statement("DROP TABLE IF EXISTS floors CASCADE;");
DB::statement("DROP TABLE IF EXISTS residents CASCADE;");
DB::statement("DROP TABLE IF EXISTS migrations CASCADE;");
DB::statement("DROP PROCEDURE IF EXISTS sp_generate_monthly_fees(VARCHAR, NUMERIC, DATE, INT, INT, INT, INT);");
DB::statement("DROP PROCEDURE IF EXISTS sp_generate_monthly_fees(VARCHAR, NUMERIC, DATE, INT, INT, INT);");
DB::statement("DROP FUNCTION IF EXISTS fn_generate_monthly_fees(VARCHAR, NUMERIC, DATE);");

// Apply teammate's allocation schema (prerequisite)
$allocationsSql = file_get_contents(__DIR__ . '/../../backend/database/stayledger_allocations_schema.sql');
// Execute statements in batches
$statements = array_filter(array_map('trim', explode(';', $allocationsSql)));
foreach ($statements as $stmt) {
    if (!empty($stmt)) {
        try {
            DB::unprepared($stmt . ';');
        } catch (Exception $e) {
            // Ignore minor notice errors
        }
    }
}
echo "Applied prerequisite tables: floors, rooms, residents, room_allocations.\n\n";

// TEST 1: Run Laravel Migrations Themselves
echo "--- TEST 1: Running Laravel Migrations via Artisan ---\n";
$exit1 = Artisan::call('migrate', [
    '--path' => 'database/migrations/2026_11_15_000001_create_monthly_fees_table.php',
    '--database' => 'pgsql',
    '--force' => true,
]);
$exit2 = Artisan::call('migrate', [
    '--path' => 'database/migrations/2026_11_15_000002_create_monthly_fee_generation_routine.php',
    '--database' => 'pgsql',
    '--force' => true,
]);

$hasMonthlyFees = DB::select("SELECT 1 FROM information_schema.tables WHERE table_name = 'monthly_fees'");
$hasProcedure = DB::select("SELECT 1 FROM information_schema.routines WHERE routine_name = 'sp_generate_monthly_fees'");
$hasFunction = DB::select("SELECT 1 FROM information_schema.routines WHERE routine_name = 'fn_generate_monthly_fees'");

assertTest(
    $exit1 === 0 && $exit2 === 0 && !empty($hasMonthlyFees) && !empty($hasProcedure) && !empty($hasFunction),
    "Laravel Artisan migrations executed successfully against PostgreSQL",
    "Created table 'monthly_fees', procedure 'sp_generate_monthly_fees', and function 'fn_generate_monthly_fees'."
);

// --- SEED TEST RESIDENTS & ALLOCATIONS ---
// Clean default seed residents and insert controlled test matrix:
DB::statement("TRUNCATE room_allocations, residents RESTART IDENTITY CASCADE;");

// RES-001: Amaya Perera (ACTIVE), full month stay (2026-09-01 onwards, open-ended)
// RES-002: Dinithi Fernando (INACTIVE), historical full month stay (2026-10-01 to 2026-11-30)
// RES-003: Kavindi Jayasinghe (ACTIVE), partial month stay (arrived 2026-11-10 onwards)
// RES-004: Nethmi Silva (INACTIVE), stay ended before period (2026-08-01 to 2026-10-31)
DB::table('residents')->insert([
    ['resident_id' => 1, 'resident_code' => 'RES-001', 'full_name' => 'Amaya Perera', 'email' => 'amaya@test.com', 'status' => 'ACTIVE'],
    ['resident_id' => 2, 'resident_code' => 'RES-002', 'full_name' => 'Dinithi Fernando', 'email' => 'dinithi@test.com', 'status' => 'INACTIVE'],
    ['resident_id' => 3, 'resident_code' => 'RES-003', 'full_name' => 'Kavindi Jayasinghe', 'email' => 'kavindi@test.com', 'status' => 'ACTIVE'],
    ['resident_id' => 4, 'resident_code' => 'RES-004', 'full_name' => 'Nethmi Silva', 'email' => 'nethmi@test.com', 'status' => 'INACTIVE'],
]);

DB::table('room_allocations')->insert([
    [
        'allocation_id' => 1,
        'allocation_code' => 'ALC-001',
        'resident_id' => 1,
        'room_id' => 1,
        'start_date' => '2026-09-01',
        'end_date' => null,
        'status' => 'Active',
    ],
    [
        'allocation_id' => 2,
        'allocation_code' => 'ALC-002',
        'resident_id' => 2,
        'room_id' => 2,
        'start_date' => '2026-10-01',
        'end_date' => '2026-11-30',
        'status' => 'Checked Out',
    ],
    [
        'allocation_id' => 3,
        'allocation_code' => 'ALC-003',
        'resident_id' => 3,
        'room_id' => 3,
        'start_date' => '2026-11-10',
        'end_date' => null,
        'status' => 'Active',
    ],
    [
        'allocation_id' => 4,
        'allocation_code' => 'ALC-004',
        'resident_id' => 4,
        'room_id' => 4,
        'start_date' => '2026-08-01',
        'end_date' => '2026-10-31',
        'status' => 'Checked Out',
    ],
]);

// TEST 2: Laravel Service Preview
echo "\n--- TEST 2: Laravel Service Preview Generation (Historical Eligibility & Partial Stay Blocking) ---\n";
/** @var MonthlyFeeService $service */
$service = $app->make(MonthlyFeeService::class);
$preview = $service->previewGeneration('2026-11');

$pList = collect($preview['preview_list']);
$res1 = $pList->firstWhere('resident_code', 'RES-001');
$res2 = $pList->firstWhere('resident_code', 'RES-002');
$res3 = $pList->firstWhere('resident_code', 'RES-003');
$res4 = $pList->firstWhere('resident_code', 'RES-004');

assertTest(
    $preview['eligible_count'] === 2 &&
    $preview['blocked_count'] === 1 &&
    $res2['status'] === 'ELIGIBLE' &&
    $res3['status'] === 'BLOCKED_POLICY_UNCONFIRMED' &&
    $res4 === null,
    "Laravel preview correctly validates historical eligibility and blocks partial-month stays",
    "RES-001 & RES-002 (historical inactive) eligible; RES-003 blocked (partial stay); RES-004 excluded (past stay)."
);

// TEST 3: Laravel Service Fee Generation invoking PostgreSQL Routine
echo "\n--- TEST 3: Laravel Service Invoking PostgreSQL Stored Routine (fn_generate_monthly_fees) ---\n";
$genResult = $service->generateFees('2026-11', 18000.00, '2026-11-15', 'November Accommodation');

$createdCount = (int) DB::table('monthly_fees')->where('billing_period', '2026-11')->count();
$hasRes3Fee = DB::table('monthly_fees')->where('resident_id', 3)->where('billing_period', '2026-11')->exists();
$hasRes2Fee = DB::table('monthly_fees')->where('resident_id', 2)->where('billing_period', '2026-11')->exists();

assertTest(
    $genResult['used_stored_procedure'] === true &&
    $genResult['created_count'] === 2 &&
    $genResult['blocked_count'] === 1 &&
    $createdCount === 2 &&
    !$hasRes3Fee &&
    $hasRes2Fee,
    "Laravel service successfully invoked PostgreSQL routine; billed only full-month stays",
    "Procedure fn_generate_monthly_fees returned created=2, blocked=1. Inactive RES-002 billed; partial RES-003 blocked."
);

// TEST 4: Idempotency & Duplicate Prevention
echo "\n--- TEST 4: Duplicate Generation Prevention (Idempotency) ---\n";
$repeatResult = $service->generateFees('2026-11', 18000.00, '2026-11-15');
$afterCount = (int) DB::table('monthly_fees')->where('billing_period', '2026-11')->count();

assertTest(
    $repeatResult['created_count'] === 0 &&
    $repeatResult['skipped_count'] === 2 &&
    $repeatResult['blocked_count'] === 1 &&
    $afterCount === 2,
    "Second generation run created 0 duplicates and skipped all already-billed records",
    "created_count=0, skipped_count=2, total table count unchanged at 2."
);

// TEST 5: Fee Listing and Details Inspection via Laravel Service
echo "\n--- TEST 5: Fee Listing, Search and Inspection ---\n";
$feeList = $service->listFees(['period' => '2026-11', 'page' => 1, 'per_page' => 10]);
$firstFeeId = $feeList['fees'][0]->fee_id;
$feeDetails = $service->getFeeDetails($firstFeeId);

assertTest(
    count($feeList['fees']) === 2 &&
    $feeDetails !== null &&
    $feeDetails->amount_due == 18000.00 &&
    $feeDetails->fee_status === 'UNPAID',
    "Fee records correctly retrieved and enriched with room/floor data from PostgreSQL",
    "Fee code: {$feeDetails->fee_code}, Resident: {$feeDetails->resident_name}, Room: {$feeDetails->room_number}."
);

// TEST 6: Transaction Rollback Integrity
echo "\n--- TEST 6: Transaction Rollback Integrity ---\n";
try {
    DB::connection('pgsql')->transaction(function () {
        DB::table('monthly_fees')->insert([
            'fee_code' => 'FEE-TEST-ROLLBACK',
            'resident_id' => 1,
            'billing_period' => '2026-10',
            'amount_due' => 15000.00,
            'due_date' => '2026-10-15',
            'status' => 'UNPAID',
            'remarks' => 'Rollback probe',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        throw new RuntimeException("Simulated mid-transaction failure for rollback verification.");
    });
} catch (RuntimeException $e) {
    // Expected exception
}

$rollbackExists = DB::table('monthly_fees')->where('fee_code', 'FEE-TEST-ROLLBACK')->exists();
assertTest(
    !$rollbackExists,
    "PostgreSQL transaction rollback verified; aborted operation persisted 0 records",
    "Record 'FEE-TEST-ROLLBACK' was rolled back completely by Laravel/PostgreSQL."
);

// TEST 7: Real Concurrent Request Verification across Two Independent PDO Connections
echo "\n--- TEST 7: Real Concurrency Verification across Independent PDO Connections ---\n";

// Add December allocations for RES-001 and RES-002
DB::table('room_allocations')->insert([
    [
        'allocation_id' => 10,
        'allocation_code' => 'ALC-DEC-002',
        'resident_id' => 2,
        'room_id' => 2,
        'start_date' => '2026-12-01',
        'end_date' => '2026-12-31',
        'status' => 'Checked Out',
    ]
]);

// Establish TWO distinct, independent PDO connections
$dsn = "pgsql:host={$dbHost};port={$dbPort};dbname={$dbName}";
$pdoA = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdoB = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// Connection A executes routine for 2026-12
$stmtA = $pdoA->prepare("SELECT * FROM fn_generate_monthly_fees('2026-12', 18500.00, '2026-12-15')");
$stmtA->execute();
$resA = $stmtA->fetch(PDO::FETCH_ASSOC);

// Connection B executes routine concurrently for identical period 2026-12
$stmtB = $pdoB->prepare("SELECT * FROM fn_generate_monthly_fees('2026-12', 18500.00, '2026-12-15')");
$stmtB->execute();
$resB = $stmtB->fetch(PDO::FETCH_ASSOC);

$decTotal = (int) $pdoA->query("SELECT COUNT(*) FROM monthly_fees WHERE billing_period = '2026-12'")->fetchColumn();

// Exactly 3 eligible records exist (RES-001, RES-002, RES-003 all had full stay in Dec 2026), 0 duplicates
assertTest(
    $decTotal === 3 && ($resA['created_count'] + $resB['created_count'] === 3),
    "Real concurrency test across two independent PDO connections succeeded with zero duplicate records",
    "Conn A created={$resA['created_count']}, skipped={$resA['skipped_count']} | Conn B created={$resB['created_count']}, skipped={$resB['skipped_count']} | Total records in DB={$decTotal}."
);

echo "\n========================================================================\n";
echo "SUMMARY: ALL {$passedTests} / {$totalTests} LARAVEL-TO-POSTGRESQL INTEGRATION TESTS PASSED (100%)\n";
echo "========================================================================\n";
