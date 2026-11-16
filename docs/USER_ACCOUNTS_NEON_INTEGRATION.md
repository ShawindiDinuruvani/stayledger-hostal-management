# StayLedger User Accounts & Access — Neon Integration

This module uses the Laravel app under `backend/laravel`. The team shares a Neon PostgreSQL database, while the current local Laravel `.env` is configured for MySQL. To integrate safely:

1. Obtain newly rotated, least-privileged development-branch credentials from the Neon owner. Never copy the hardcoded PostgreSQL URL from the existing PHP backend into committed files.
2. Check the existing `public.users` schema and its `email` unique index before any schema migrations; coordinate with the other Laravel backend before altering shared tables.
3. Do not run `migrate:fresh`, rollback, or full migrations against the shared Neon database.
4. Apply the reviewed, non-destructive `role` and `is_active` schema upgrade only after validating the shared schema and backup plan.
5. Treat browser UI restrictions separately from backend authorization; native PHP and separate Laravel applications do not share sessions automatically.

The project already has login/logout, user creation/edit and role controls, but live Neon connectivity and multi-backend authentication are not yet verified.
