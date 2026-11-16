# StayLedger — Emarald Girls Hostel

**CS304.3 Advanced Database Management Systems · Group L**

Hostel management system built by a ten-member team.

## Architecture

| Component | Technology | Development port |
| --- | --- | --- |
| Multi-module frontend | React / Vite | 5173 |
| Room Allocations and other existing modules | Native PHP / PDO | 8000 |
| Monthly Fees (teammate-owned) | Laravel | 8001 |
| **User Accounts & Access** | Laravel | **8002** |
| Team database | PostgreSQL / Neon | Hosted |

The frontend forwards `/laravel/*` requests to the User Accounts backend (port 8002 by default). The Monthly Fees Laravel service keeps its separate port. The team has **not yet implemented single sign-on** across the native PHP backend and the two Laravel applications; sharing Neon does not share sessions.

## User Accounts & Access module

The user module implements:

- Admin/Staff login, logout, sessions and CSRF protection.
- User creation, listing, search, editing and account activation/deactivation.
- Role-based access control enforced on Laravel routes.
- Unique and case-insensitive email validation, password hashing and a 12-character minimum.
- My Account password changes and administrator-initiated password resets.
- Password-reset session revocation for sessions established with the current login code.
- Safeguards against removing your own admin role or disabling the final active administrator.
- Non-destructive PostgreSQL migration for the shared `users` table.
- Automated Laravel/SQLite security tests and React production build on GitHub Actions.

## Development

Run the existing native PHP backend on port **8000**, Monthly Fees Laravel on **8001**, and the User Accounts Laravel app at `backend/laravel` on **8002**. Run Vite from `frontend/` on **5173**.

For the User Accounts Laravel app, install Composer dependencies and configure an ignored `.env`; never commit database secrets. When starting that backend:

```bash
cd backend/laravel
php artisan serve --host=127.0.0.1 --port=8002
```

Frontend:

```bash
cd frontend
npm ci
npm run dev
```

The optional `frontend/.env.example` documents API origins. Laravel's `backend/laravel/.env.neon.example` is a **placeholder**, not real credentials.

## Tests and database safety

The `Kulara` branch runs a GitHub Actions workflow for isolated SQLite Laravel tests and the React build.

**Live Neon verification is pending** until the team provides rotated credentials for an isolated Neon development branch. Before any Neon schema write, use the read-only `php artisan stayledger:inspect-shared-users` command and review `backend/laravel/NEON_USER_ACCESS_INTEGRATION.md`. Do not run `migrate:fresh`, rollback, or an unreviewed full migration set on the team database.

The previously committed connection URL in `backend/db.php` was exposed. The Neon owner must rotate that credential, remove it from repository source and assess Git history.

## Team collaboration

This is the `Kulara` member branch. Team integration should be reviewed and coordinated against the `Dev` branch. Do not merge user-account backend migrations blindly with the monthly-fees Laravel migrations because the apps share table names and migration history.
