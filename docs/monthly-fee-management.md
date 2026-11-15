# Monthly Fee Management Module Documentation

**Project:** StayLedger  
**Organization:** Emarald Girls Hostel  
**University Module:** CS304.3 Advanced Database Management Systems (ADBMS)  
**Module Owner:** S. Dinuruvani (Student ID: 36750)  
**Working Branch:** `oviya`  

---

## 1. Module Scope & Team Responsibility Matrix

Under the agreed team allocation for the StayLedger ADBMS project, responsibilities are partitioned as follows:

| Team Member | Module Scope | Implementation Architecture | Default Port / Service URL |
| :--- | :--- | :--- | :--- |
| **S. Dinuruvani (36750)** | **Monthly Fee Management** | **Laravel 13 Application (`backend-laravel/`)** | **Port 8001 (`http://localhost:8001/api`)** |
| **A.K.S. Athukorala** | **User Accounts & Role-Based Access** | Native PHP Backend (`backend/`) | Port 8000 (`http://localhost:8000/api`) |
| **H.D.T.A. Karunarathna** | **Payments & Outstanding Balances** | Native PHP Backend (`backend/`) | Port 8000 (`http://localhost:8000/api`) |
| **N.L. Lekamge (36934)** | **Room Allocations, Transfers & Checkout** | Native PHP Backend (`backend/`) | Port 8000 (`http://localhost:8000/api`) |
| **Teammate** | **Shared Kitchens & Expense Reports** | Native PHP Backend (`backend/`) | Port 8000 (`http://localhost:8000/api`) |

### Strict Architectural Boundaries
1. **Isolated Laravel Application:** S. Dinuruvani's module is implemented in `backend-laravel/`, satisfying the mandatory course requirement to use Laravel without overwriting or breaking teammates' native PHP modules in `backend/`.
2. **Zero Modification to Teammates' Code:** The checked-out native PHP backend (`backend/index.php`, `backend/controllers/`, `backend/database/stayledger_allocations_schema.sql`) remains completely intact (`git diff backend/` is 0).
3. **No Unilateral Branching or Commits:** All work is conducted strictly on branch `oviya`. No commits, pushes, merges, deployments, or pull requests are executed.

### 1.2 Backend Folder Structure & Unification Scope
- **`backend/` (Native PHP Application):** Contains the original team backend router and native PHP PDO controllers for teammates' modules (Room Allocations by N.L. Lekamge, Shared Kitchens, etc.) running on Port 8000.
- **`backend-laravel/` (Laravel 13 Application):** Contains S. Dinuruvani's Monthly Fee Management module running on Port 8001.
- **Why Both Directories Exist:** Combining both applications into one Laravel application would require migrating and rewriting teammates' native PHP controllers, modifying their routes, and changing their code. That refactoring is outside Dinuruvani's module scope and would disrupt teammates' working code. Folder renaming alone does not unify the codebase; a single unified Laravel backend requires a coordinated team-wide migration.
- **Database Migrations Source of Truth:**
  - Authoritative runtime migrations: `backend-laravel/database/migrations/` (`2026_11_15_000001_create_monthly_fees_table.php` and `2026_11_15_000002_create_monthly_fee_generation_routine.php`).
  - Standalone SQL reference: `backend/database/stayledger_monthly_fees_schema.sql` is maintained purely for direct PostgreSQL / ADBMS course submission inspection.
- **Cleaned Obsolete Files:** No obsolete copies of Monthly Fee controllers exist in `backend/`. A recoverable backup of the initial exploratory native PHP controller is safely archived in scratch storage (`scratch/backup-native-php/`).

---

## 2. Port & API Routing Configuration

To allow independent operation without port collisions, the following routing architecture is enforced:

| Component | Working Directory | Runtime Command | Local Port | Base URL |
| :--- | :--- | :--- | :--- | :--- |
| **Teammates' Backend** | `backend/` | `php -S localhost:8000 -t public` | `8000` | `http://localhost:8000/api` |
| **Monthly Fees Backend** | `backend-laravel/` | `php artisan serve --port=8001` | `8001` | `http://localhost:8001/api` |
| **Frontend Application** | `frontend/` | `npm run dev` | `5173` | Browser UI (`http://localhost:5173/#fees`) |

### Frontend Integration Isolation
- `frontend/src/allocations/RoomAllocations.jsx`: Connects to `http://localhost:8000/api` (unmodified).
- `frontend/src/kitchens/KitchenExpenseReport.jsx`: Connects to `http://localhost:8000/api` (unmodified).
- `frontend/src/fees/MonthlyFees.jsx`: Uses `import.meta.env.VITE_LARAVEL_API_URL || 'http://localhost:8001/api'`.
- Configuration template committed in [frontend/.env.example](file:///f:/project/StayLedger-Dinuruvani/frontend/.env.example).

---

## 3. Historical Eligibility & Partial-Month Billing Rules

### 3.1 Eligibility Rule Formulation
Fee eligibility is determined by real hostel occupancy documented in `room_allocations`:
For any given billing period $P = \text{YYYY-MM}$ with calendar start date $D_{\text{start}} = \text{YYYY-MM-01}$ and end date $D_{\text{end}} = \text{last day of month}$:

$$\text{Stay Overlap Condition: } \quad \text{ra.start\_date} \le D_{\text{end}} \quad \land \quad (\text{ra.end\_date IS NULL} \lor \text{ra.end\_date} \ge D_{\text{start}})$$

### 3.2 Historical Eligibility (Inactive Resident Inclusion)
- **Problem Corrected:** The previous implementation strictly required `residents.status = 'ACTIVE'`. If a resident checked out in December 2026 and had their profile status set to `INACTIVE`, they were erroneously excluded when generating or inspecting fees for November 2026.
- **Rule Enforced:** Current inactive status does **NOT** exclude a resident who had a valid room allocation stay during that billing period. If a resident resided in the hostel throughout November 2026, they are fully eligible for November 2026 billing regardless of whether their current status is `ACTIVE` or `INACTIVE`.

### 3.3 Server-Side Enforcement of Partial-Month Stay Blocking
- **Hostel Policy Status:** Emarald Girls Hostel management has **not** confirmed a prorating formula (such as day-count prorating, half-month tiers, or charging full monthly fees for partial stays).
- **Compliance Policy:** In accordance with course ADBMS specifications, artificial charging policies are not invented.
- **Enforcement Mechanism:**
  - Let $D_{\text{arrival}} = \min(\text{ra.start\_date})$ and $D_{\text{departure}} = \max(\text{ra.end\_date})$ across overlapping allocations.
  - If $D_{\text{arrival}} > D_{\text{start}}$ OR ($D_{\text{departure}} \ne \text{null} \land D_{\text{departure}} < D_{\text{end}}$):
    - **In Preview:** Classified as `BLOCKED_POLICY_UNCONFIRMED`. Displayed in preview roster with badge `Blocked: Partial Stay` and explanation: *"Partial-month stay detected; prorating vs full-month policy unconfirmed by hostel management."* Counted in `blocked_count` and excluded from `eligible_count`.
    - **In Server-Side Generation (`MonthlyFeeService` & `sp_generate_monthly_fees`):** Generation strictly **blocks** fee creation for partial-month residents. Zero fee records are inserted, preventing accidental overcharging.
- **Unified Logic:** Preview (`previewGeneration`) and generation (`generateFees`) share the identical resolution routine (`resolvePeriodEligibility`), guaranteeing 100% consistency.

---

## 4. Authentication Architecture & Integration Boundary

### 4.1 Boundary Ownership
- User accounts, password hashing, JWT/session issuance, and role-based permissions (Warden, Admin, Resident) belong strictly to the **Users & Access** module owned by **A.K.S. Athukorala**.
- In strict adherence to scope, Dinuruvani's module does **not** implement or duplicate teammate Athukorala's authentication system, nor does it invent arbitrary mock tokens or runtime backdoors.

### 4.2 Middleware Implementation (`EnsureAuthenticated`)
- Located in [backend-laravel/app/Http/Middleware/EnsureAuthenticated.php](file:///f:/project/StayLedger-Dinuruvani/backend-laravel/app/Http/Middleware/EnsureAuthenticated.php).
- Custom `X-Test-Authorization` header parsing has been **completely removed**.
- Relies strictly on standard Laravel authentication (`$request->user()`):
  ```php
  if ($request->user()) {
      return $next($request);
  }
  return response()->json([
      'message' => 'Unauthorized. Authentication integration pending: user accounts and role-based access management are maintained by the Users & Access module (A.K.S. Athukorala). Protected operations are denied until established authentication is integrated.',
      'integration_status' => 'AUTH_INTEGRATION_PENDING',
  ], 401);
  ```

### 4.3 Automated Testing Authentication
- Test suites authenticate using Laravel's native testing facility:
  `$this->actingAs(new GenericUser(['id' => 1, 'name' => 'Hostel Warden']))`.
- Confined exclusively to the test runner; zero runtime bypass in production middleware.

### 4.4 Blocked Browser Workflows
Until teammate A.K.S. Athukorala delivers and integrates the authentication provider:
- **Fee Ledger View (`GET /api/fees`):** Denied at runtime with HTTP 401 (`AUTH_INTEGRATION_PENDING`). The UI renders an informative warning banner.
- **Eligibility Preview (`GET /api/fees/preview`):** Denied at runtime with HTTP 401.
- **Fee Generation (`POST /api/fees/generate`):** Denied at runtime with HTTP 401.

---

## 5. Database Safety & PostgreSQL Verification

### 5.1 Non-Secret Evidence of Hosted Database
- Configured PDO driver: `pgsql`.
- Documented Neon endpoint: ends with `.neon.tech` (AWS `ap-southeast-1` region) with `sslmode=require`.
- **Database Safety Compliance:** In strict adherence to testing safety, the shared hosted Neon database was **never** connected to, queried, or mutated.

### 5.2 Isolated PostgreSQL Test Cluster
All database and procedural verification was conducted against an isolated PostgreSQL 17.11 instance running locally on Port **5433** (`stayledger_isolated_test`).

### 5.3 Stored Procedure & Routine Architecture
- **Routine Migration:** [backend-laravel/database/migrations/2026_11_15_000002_create_monthly_fee_generation_routine.php](file:///f:/project/StayLedger-Dinuruvani/backend-laravel/database/migrations/2026_11_15_000002_create_monthly_fee_generation_routine.php)
- **Reference Script:** [backend/database/stayledger_monthly_fees_schema.sql](file:///f:/project/StayLedger-Dinuruvani/backend/database/stayledger_monthly_fees_schema.sql)
- **Stored Procedure (`sp_generate_monthly_fees`):**
  - Inputs: `p_billing_period` (VARCHAR), `p_amount` (NUMERIC), `p_due_date` (DATE).
  - InOut Counters: `p_created_count`, `p_skipped_count`, `p_total_eligible`, `p_blocked_count`.
  - Concurrency Safety: Uses `INSERT INTO monthly_fees ... ON CONFLICT (resident_id, billing_period) DO NOTHING RETURNING fee_id`.
  - Transaction Boundary: Managed at application/service level; does not issue premature COMMIT or ROLLBACK.
- **Function Wrapper (`fn_generate_monthly_fees`):**
  - Returns `TABLE (created_count INT, skipped_count INT, total_eligible INT, blocked_count INT)` allowing direct PDO/Eloquent querying.

---

## 6. Comprehensive Test Execution Report

The test suite is partitioned into two distinct categories:

### 6.1 Category A: Laravel Unit & Feature Tests (SQLite In-Memory)
- **Working Directory:** `backend-laravel/`
- **Command:** `php -d extension=pdo_sqlite -d extension=pdo_pgsql vendor/bin/phpunit --testdox`
- **Result:** **13 Passed, 1 Skipped (100% of runnable tests passed)**

```
Monthly Fee Api (Tests\Feature\MonthlyFeeApi)
 ✔ Unauthorized access denied without authentication
 ✔ Authorized access succeeds under test facility
 ✔ Preview endpoint historical eligibility and partial stay blocking
 ✔ Generate endpoint validates inputs
 ✔ Generate fees enforces partial stay blocking and idempotency
 ✔ Fee list handles unavailable payment data honestly
 ✔ Fee list search and period filters
 ✔ Fee details and not found

Monthly Fee Validation (Tests\Unit\MonthlyFeeValidation)
 ✔ Billing period format validation
 ✔ Fee amount must be positive
 ✔ Preview request validation

Example Tests
 ✔ The application returns a successful response
 ✔ That true is true

Postgres Stored Procedure Integration (Tests\Feature\PostgresStoredProcedureIntegration)
 ↩ Postgres plpgsql routine execution (skipped in SQLite run; covered in Category B)
```

### 6.2 Category B: Laravel-to-PostgreSQL Real Integration Tests
- **Working Directory:** `backend-laravel/`
- **Database:** Real PostgreSQL 17.11 on `127.0.0.1:5433/stayledger_isolated_test`
- **Command:** `php -d extension=pdo_pgsql -d extension=pdo_sqlite tests/run_laravel_postgres_integration_tests.php`
- **Result:** **7 / 7 Passed (100%)**

| Test # | Test Name | Target Verified | Result |
| :---: | :--- | :--- | :---: |
| **1** | Laravel Artisan Migrations Execution | `Artisan::call('migrate')` successfully applied `monthly_fees` table, `sp_generate_monthly_fees` procedure, and `fn_generate_monthly_fees` function. | **PASS** |
| **2** | Preview Generation Eligibility Validation | Verified currently inactive resident (RES-002) with valid historical stay is `ELIGIBLE`; resident with partial stay (RES-003) is `BLOCKED_POLICY_UNCONFIRMED`; past resident (RES-004) excluded. | **PASS** |
| **3** | Laravel Service Invoking PostgreSQL Routine | `MonthlyFeeService->generateFees()` invoked `fn_generate_monthly_fees` with `used_stored_procedure=true`. Billed only full-month stays (created=2, blocked=1). | **PASS** |
| **4** | Idempotency & Duplicate Prevention | Second generation run executed with `created_count=0`, `skipped_count=2`, `blocked_count=1`. Total records remained exactly 2. | **PASS** |
| **5** | Enriched Fee Inspection | `MonthlyFeeService->listFees()` and `getFeeDetails()` correctly returned joined room number, floor name, and resident contact data. | **PASS** |
| **6** | Transaction Rollback Integrity | Aborted transaction within Laravel/PostgreSQL resulted in 0 persisted records; atomic rollback confirmed. | **PASS** |
| **7** | Real Concurrency Verification | Two independent PDO connections (`pdoA` and `pdoB`) invoked `fn_generate_monthly_fees` concurrently for December 2026. Exactly 3 records created, 0 duplicates, 0 constraint collisions. | **PASS** |

### 6.3 Category C: Frontend Production Build
- **Working Directory:** `frontend/`
- **Command:** `npm run build`
- **Result:** **Built successfully in 10.02s (Exit code: 0)**
  - Chunks: `dist/index.html` (0.45 kB), `dist/assets/index-BlP1wHBZ.css` (24.24 kB), `dist/assets/index-dWj2DV_c.js` (201.37 kB).

---

## 7. Repository Hygiene & Git Audit

1. **Gitignore Protection:**
   - Root `.gitignore` and `backend-laravel/.gitignore` strictly ignore all `.env`, `.env.*` (preserving only committed `.env.example`), logs, scratch files, SQLite databases, `node_modules/`, and `vendor/`.
2. **Untracked / Clean Tree Audit:**
   - `backend/` directory has **0 modified files** (`git diff backend/` is completely empty).
   - Only Dinuruvani's authorized module files exist:
     - `backend-laravel/` (isolated Laravel backend)
     - `frontend/src/fees/` (Monthly Fees UI component)
     - `frontend/.env.example` & `backend-laravel/.env.example` (safe templates)
     - `docs/monthly-fee-management.md` (this specification document)
     - `backend/database/stayledger_monthly_fees_schema.sql` (reference schema)
     - `frontend/src/main.jsx` (route registration for `#fees`)
     - `frontend/package-lock.json`
     - `.gitignore` (hygiene rules)
3. **Branch Status:** On branch `oviya`. No commits, pushes, merges, or deployments made.

---

## 8. Neon Cloud Database State & Remaining Foreign Key Repair Steps

### 8.1 Verified Current Neon Cloud State
Direct read-only inspection of the team Neon database (`ep-lingering-pine-b3dk5yfn-pooler...aws.neon.tech:5432/stayledger_db`) confirms:
- **`monthly_fees` Table:** Created and operational with primary key `monthly_fees_pkey`, unique code index, unique constraint `uq_resident_billing_period`, billing period regex check `chk_billing_period_fmt`, positive amount check `chk_amount_due_positive`, and 0 rows.
- **Routines:** Both `sp_generate_monthly_fees` (procedure) and `fn_generate_monthly_fees` (function) are compiled and present in the `public` schema.
- **Migration History:** Migrations `#1` through `#5` are recorded in the `migrations` catalog under batch 1.
- **Pending Relational Dependency:** Teammate N.L. Lekamge's prerequisite tables (`residents`, `rooms`, `floors`, `room_allocations`) have not yet been deployed to Neon. Consequently, `monthly_fees` currently lacks the `monthly_fees_resident_id_foreign` constraint.

### 8.2 Additive Repair Migration
To establish strict referential integrity without deleting data or rebuilding existing structures, an additive repair migration is prepared:
- **File:** `backend-laravel/database/migrations/2026_11_15_000003_add_residents_foreign_key_to_monthly_fees_table.php`
- **Safeguards Enforced:**
  1. **Prerequisite Check:** Explicitly verifies `Schema::hasTable('residents')`. If missing, execution halts safely with a descriptive message rather than silently skipping the constraint.
  2. **Type Compatibility Check:** Asserts `residents.resident_id` has a primary key and compatible integer type matching `monthly_fees.resident_id`.
  3. **Zero Orphan Record Guarantee:** Queries for any orphaned `resident_id` in `monthly_fees`. If orphans exist, halts immediately without modifying or deleting data.
  4. **Atomic Attachment:** Attaches `FOREIGN KEY (resident_id) REFERENCES residents(resident_id) ON DELETE RESTRICT`.
  5. **Clean Reversal:** Supports `migrate:rollback` to remove the foreign key while preserving table and financial data intact.

### 8.3 Exact Steps When Teammates' Schema Becomes Available
Once teammate N.L. Lekamge restores/creates the allocation and resident tables on Neon:

1. **Verify `residents` exists on Neon:**
   ```powershell
   php -d extension=pdo_pgsql -r "require 'backend-laravel/vendor/autoload.php'; \$app = require_once 'backend-laravel/bootstrap/app.php'; \$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); var_dump(Illuminate\Support\Facades\Schema::connection('pgsql')->hasTable('residents'));"
   ```
2. **Execute only the additive repair migration:**
   ```powershell
   php -d extension=pdo_pgsql backend-laravel/artisan migrate --database=pgsql --path=database/migrations/2026_11_15_000003_add_residents_foreign_key_to_monthly_fees_table.php
   ```
3. **Verify attached constraint on Neon (read-only):**
   ```powershell
   php -d extension=pdo_pgsql -r "require 'backend-laravel/vendor/autoload.php'; \$app = require_once 'backend-laravel/bootstrap/app.php'; \$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); print_r(Illuminate\Support\Facades\DB::connection('pgsql')->select(\"SELECT conname, pg_get_constraintdef(oid) FROM pg_constraint WHERE conrelid = 'monthly_fees'::regclass AND contype = 'f'\"));"
   ```
