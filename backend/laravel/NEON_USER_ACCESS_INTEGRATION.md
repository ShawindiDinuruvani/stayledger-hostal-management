# StayLedger User Accounts — PostgreSQL / Neon compatibility

The StayLedger team has more than one backend (native PHP, `backend-laravel` and `backend/laravel`). Sharing a PostgreSQL database does **not** make their login sessions interoperable. Keep protected endpoints behind their own authorization checks until authentication is explicitly integrated between apps.

## Safe workflow

1. Ask the database owner for **rotated credentials** for an isolated Neon development branch. A URL embedded in the existing `backend/db.php` was exposed and must not be reused.
2. In **local, ignored** `backend/laravel/.env` configure `DB_CONNECTION=pgsql`, `DB_HOST`, `DB_PORT=5432`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` and `DB_SSLMODE=require`. Never commit secrets.
3. Before any database writes run `php artisan stayledger:inspect-shared-users`. This command is read-only and returns a nonzero exit code if the shared schema isn't ready.
4. Confirm with the DB owner that the accounts table, migration history, and backups are coordinated. The migration `2026_11_16_000003_reconcile_shared_user_access_fields.php` adds missing role and activation fields without dropping data and checks email uniqueness first. **Do not blindly run `php artisan migrate` on a shared production database.**
5. Existing accounts without roles will be marked **staff**; a trusted administrator must explicitly assign the correct admin account. No account is automatically elevated.
6. Run the isolated SQLite test suite (`php artisan test`) before configuring the Neon development branch. It must not connect to production.

## Data boundaries and limits

- The migration does not overwrite passwords, names, emails or existing roles.
- It does not create a default admin or copy users from local MySQL.
- A successful unit test or PHP build is **not** proof of Neon connectivity.
- Sessions for the native PHP allocation API and the team's second Laravel app still require an agreed authorization mechanism.
