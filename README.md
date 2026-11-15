# StayLedger

Hostel Management System for Emarald Girls Hostel.

## Project Details
- Module: CS304.3 Advanced Database Management Systems
- Organization: Emarald Girls Hostel
- Database Engine: PostgreSQL

## Technology Stack & Architecture
- **Frontend:** React 18 / Vite
- **Team Modules Backend:** Native PHP with PDO (`backend/`)
- **Monthly Fee Management Backend:** Laravel 13 (`backend-laravel/`)
- **Database:** PostgreSQL (with PL/pgSQL stored procedures)

## Backend Directory Structure & Module Ownership

| Directory | Architecture | Module Ownership | Default Port | Base API URL |
| :--- | :--- | :--- | :--- | :--- |
| `backend/` | Native PHP / PDO | Teammates' modules: Room Allocations & Checkout (N.L. Lekamge), Shared Kitchens & Expenses | `8000` | `http://localhost:8000/api` |
| `backend-laravel/` | Laravel 13 | Monthly Fee Management (S. Dinuruvani - 36750) | `8001` | `http://localhost:8001/api` |
| `frontend/` | React 18 / Vite | Multi-module UI (Allocations on Port 8000; Monthly Fees on Port 8001) | `5173` | Browser (`http://localhost:5173`) |

### Why Two Backend Directories Exist
- S. Dinuruvani's module is required to be implemented in Laravel.
- Teammates' modules were developed and checked out as native PHP scripts in `backend/`.
- To avoid breaking teammates' modules or modifying their working code, Dinuruvani's Monthly Fee module is isolated in `backend-laravel/`.
- **Note on Unification:** Merging all modules into a single unified Laravel backend would require rewriting teammates' controllers, routes, and PDO queries. Folder renaming alone does not unify the applications. A single unified backend requires a separate, coordinated team migration.

## Database Migrations & Schemas (Source of Truth)
- **Authoritative Runtime Migrations (Monthly Fees):**
  - `backend-laravel/database/migrations/2026_11_15_000001_create_monthly_fees_table.php`
  - `backend-laravel/database/migrations/2026_11_15_000002_create_monthly_fee_generation_routine.php`
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
cd backend-laravel
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
