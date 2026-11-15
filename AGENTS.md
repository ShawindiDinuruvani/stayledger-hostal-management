# AGENTS.md

## Repo map (read this first)

This is not a single monorepo app. Four independent pieces share one git repo:

- `frontend/` - the shipped React 18 + Vite 5 UI. Hash routing in `frontend/src/main.jsx`: `#allocations` (default), `#kitchens`, `#expenses`. No lint or test scripts.
- `backend/` - the plain-PHP API actually used by `frontend/` (not Laravel). `backend/index.php` is a manual router; controllers live in `backend/app/Http/Controllers/`; PDO/Postgres connection is `backend/db.php`. Routes are only `/api/allocations*`, `/api/kitchens`, and `/api/expenses`; anything else 404s. Frontend hardcodes `http://localhost:8000/api`.
- `backend-laravel/` - separate Laravel 13 app implementing only Monthly Fees (`/api/fees`). Not called by `frontend/`. All fee routes go through `EnsureAuthenticated` and return 401 without a Laravel-authenticated user.
- `design-reference/` - throwaway React prototype with fictional data. Do not copy its root config into `frontend/` or treat it as the app.

`README.md` claims "backend/ - Laravel" and "MySQL"; the running backend is plain PHP + Postgres. Trust the code.

## Commands

Frontend:
```powershell
cd frontend
npm install
npm run build
npm run dev
```

PHP API (from repo root; the router script is required for `/api/*`):
```powershell
php -S 127.0.0.1:8000 -t backend backend/index.php
```
Needs `pdo_pgsql`. It is enabled in the local PHP 8.5 `php.ini`; fresh machines must uncomment `extension=pdo_pgsql`.

Laravel:
```powershell
cd backend-laravel
composer install
php artisan key:generate   # .env can ship with APP_KEY empty; ExampleTest fails without it
composer test              # php -d extension=pdo_sqlite -d extension=pdo_pgsql vendor/bin/phpunit
php artisan test --filter=MonthlyFeeValidationTest
```
`backend-laravel/AGENTS.md` and `CLAUDE.md` are stock Laravel Boost bootstrap placeholders; Boost is not installed, so ignore their install instructions.

## Database / Neon

- Neon is linked through `.neon/` and `.env.local` (both gitignored). `neon deploy` applies `neon.ts`, which currently contains only branch policy, no services.
- `backend/db.php` reads `DATABASE_URL` (env wins); its fallback is the pooled `stayledger_db` Neon URL. Keep pooling; `PDO::ATTR_EMULATE_PREPARES => true` is required because PgBouncer transaction pooling breaks PDO named prepared statements inside transactions.
- Use `DATABASE_URL_UNPOOLED` for schemas, migrations, dumps, and session-state work. `backend/` has no migration runner; schemas are plain SQL in `backend/database/`.
- `stayledger_allocations_schema.sql` is the authoritative Postgres schema, but it starts with `DROP TABLE` and seeds demo data - do not run it against shared Neon without confirmation. `stayledger_monthly_fees_schema.sql` mirrors the Laravel migrations; Laravel migrations are the source of truth. `stayledger_kitchen.sql` is MySQL syntax, references tables it never creates, and cannot run on Neon as-is.
- The Expenses page uses `/api/expenses` backed by the `hostel_expenses` table; schema in `backend/database/stayledger_expenses_schema.sql`. The kitchen endpoint returns `category`/`total_expense`, while `KitchenExpenseReport.jsx` expects `category_name`/`amount` - pre-existing mismatch.

## Conventions / gotchas

- Every frontend page renders its own `<Sidebar />`. Add a page by importing it in `frontend/src/main.jsx` and adding a `currentRoute` branch; the sidebar already links hashes such as `#expenses`.
- `frontend/src/allocations/allocations.css` is the shared visual system (variables, sidebar, buttons, table, modal, toast). Import it into new pages that need the shell, then add page-specific CSS.
- `RoomAllocations.jsx` error text still mentions MySQL/XAMPP even though the DB is Postgres/Neon.
- The root `package.json` was created by `neon init` and has only Neon deps, no scripts. Run builds/tests from `frontend/` or `backend-laravel/`, not the repo root.
- Do not weaken `EnsureAuthenticated` with a token bypass; tests are expected to use `actingAs`.
- The custom Postgres runners in `backend-laravel/tests/run_*.php` require a local PostgreSQL on `127.0.0.1:5433` and are written to never target Neon.
