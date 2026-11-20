# StayLedger

Hostel Management System for Emarald Girls Hostel.

## Project Details
- Module: CS304.3 Advanced Database Management Systems
- Organization: Emarald Girls Hostel
- Database Engine: PostgreSQL

## Technology Stack & Architecture
- **Frontend:** React 18 / Vite
- **Team Modules Backend:** Native PHP with PDO (`backend/`)
- **Monthly Fee Management Backend:** Laravel 13 (`backend/laravel/`)
- **Database:** PostgreSQL (with PL/pgSQL stored procedures)

## Backend Directory Structure & Module Ownership

| Directory | Architecture | Module Ownership | Default Port | Base API URL |
| :--- | :--- | :--- | :--- | :--- |
| `backend/` | Native PHP / PDO | Teammates' modules: Room Allocations & Checkout (N.L. Lekamge), Shared Kitchens & Expenses | `8000` | `http://localhost:8000/api` |
| `backend/laravel/` | Laravel 13 | Monthly Fee Management (S. Dinuruvani - 36750) | `8001` | `http://localhost:8001/api` |
| `frontend/` | React 18 / Vite | Multi-module UI (Allocations on Port 8000; Monthly Fees on Port 8001) | `5173` | Browser (`http://localhost:5173`) |

### Why Two Backend Directories Exist
- S. Dinuruvani's module is required to be implemented in Laravel.
- Teammates' modules were developed and checked out as native PHP scripts in `backend/`.
- To avoid breaking teammates' modules or modifying their working code, Dinuruvani's Monthly Fee module is isolated in `backend/laravel/`.
- **Note on Unification:** Merging all modules into a single unified Laravel backend would require rewriting teammates' controllers, routes, and PDO queries. Folder renaming alone does not unify the applications. A single unified backend requires a separate, coordinated team migration.

## Team Development Workflow & Branching Strategy
- **Development Branch (`Dev`):** The team utilizes the `Dev` branch as the central integration and testing branch.
- **Pull Requests:** Feature branches should submit pull requests targeting `Dev` rather than pushing directly to `main`.
- **Review & Merge:** Team lead / PM reviews PRs, resolves any integration conflicts on `Dev`, and merges.
- **Keeping Local Branches Updated:** Teammates should pull the latest updates from `Dev` (`git pull origin Dev`) to minimize merge conflicts.

> **Team Note:** Please coordinate pull requests through the `Dev` branch for shared testing before preparing final releases into `main`.

## Database Migrations & Schemas (Source of Truth)
- **Authoritative Runtime Migrations (Monthly Fees):**
  - `backend/laravel/database/migrations/2026_11_15_000001_create_monthly_fees_table.php`
  - `backend/laravel/database/migrations/2026_11_15_000002_create_monthly_fee_generation_routine.php`
  - `backend/laravel/database/migrations/2026_11_15_000003_add_residents_foreign_key_to_monthly_fees_table.php`
- **Standalone SQL Reference:**
  - `backend/database/stayledger_monthly_fees_schema.sql` (provided for direct PostgreSQL / ADBMS course submission inspection without the Laravel CLI).
- **Allocations Schema:**
  - `backend/database/stayledger_allocations_schema.sql`

## Running the Applications

### 1. Run Teammates' Native PHP Backend (Port 8000)
```bash
cd backend
php -S localhost:8000
```

### 2. Run Monthly Fee Laravel Backend (Port 8001)
```bash
cd backend/laravel
composer install
php artisan migrate
php artisan serve --port=8001
```

### 3. Run Frontend Application (Port 5173)
```bash
cd frontend
npm install
npm run dev
```

The Monthly Fees module connects to `http://localhost:8001/api` (configurable via `VITE_LARAVEL_API_URL` in `frontend/.env`), while teammates' pages connect to `http://localhost:8000/api`.

## Documentation
- Detailed Monthly Fee Management specifications: [docs/monthly-fee-management.md](docs/monthly-fee-management.md)

## User Accounts & Access (Kulara)

The independent Laravel 13 application in `backend/laravel/` provides administrator/staff authentication, user creation and editing, role-based access, activation controls, self-service password updates, administrator password resets, and automated security tests.

| User Accounts service | Development port | API prefix from React |
| --- | --- | --- |
| `backend/laravel/` | **8002** | `/laravel/*` (Vite proxy) |

Use `php artisan serve --host=127.0.0.1 --port=8002` from `backend/laravel/`. The `frontend/.env.example` documents both Laravel API origins without storing secrets. GitHub Actions on `Kulara` runs isolated SQLite tests and the React build.

**Neon integration status:** the shared production database has NOT been connected or migrated by this module. Before using the real PostgreSQL database, rotate any exposed credentials, inspect the shared `users` schema with the read-only `stayledger:inspect-shared-users` command, and review `backend/laravel/NEON_USER_ACCESS_INTEGRATION.md`. Never run unreviewed `migrate:fresh` or a full migration set on the shared database. Separate PHP and Laravel services do not share login sessions automatically.

For team integration, first review the complete application on `Dev`; the team lead should decide when to merge this PR into `main`.
