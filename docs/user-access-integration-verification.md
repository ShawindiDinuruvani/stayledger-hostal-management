# StayLedger — User Accounts final integration verification

## What we have verified

- Laravel authentication, admin/staff authorization, password changes, user CRUD,
  activation rules and non-destructive schema upgrades are covered by isolated tests.
- CI runs the Laravel suite with both SQLite and a disposable **PostgreSQL 16**
  database. Neither database is connected to Neon. The PostgreSQL job also runs
  `php artisan stayledger:inspect-shared-users` without modifying records.
- React/Vite production builds in CI.
- Browser navigation tests run the actual React app and User Accounts Laravel
  service on disposable SQLite; the other modules' **page rendering** is checked,
  but their data APIs are not claimed to be integrated.

## Actual cross-module authentication status

| Path | Backend | Authentication boundary |
| --- | --- | --- |
| `/#users` | `backend/laravel` (8002) | Laravel admin-only routes |
| `/#account` | `backend/laravel` (8002) | Laravel session |
| `/#allocations` | native PHP `backend/index.php` (8000) | **No shared authentication yet** |
| `/#kitchens` | native PHP `backend/index.php` (8000) | **No shared authentication yet** |
| `/#fees` | `backend-laravel` (8001) | Own Laravel guard; rejects unauthenticated API calls |

**Do not treat hiding a page in React as API authorization.** A Laravel
session established on port 8002 is not automatically available to the native
PHP server or the independent Monthly Fees Laravel service.

Cross-service authentication requires a team-approved single-sign-on design,
such as a shared identity/session gateway. No production auth bypass,
shared secret or unsigned token has been introduced.

## Live Neon database: explicit release gate

1. The database owner must **rotate previously exposed connection credentials**
   and remove secrets from `backend/db.php` and related Git history.
   Do not put the replacement password in ChatGPT messages or GitHub commits.
2. Connect the owner-approved Neon project using the Neon connection in
   ChatGPT, or use the developer's **local ignored** `.env`.
3. Verify you are using a fresh Neon *development branch* before writing.
   Do not run `migrate:fresh`, a blind `migrate`, or `rollback` on shared
   production Neon.
4. Perform read-only checks on the development branch:
   - Table `public.users` exists;
   - Columns `id`, `name`, `email`, `password`, `role`, `is_active` exist;
   - `email` is unique;
   - `role` accepts exactly `admin` and `staff`;
   - Existing passwords use valid framework hashes;
   - At least one intentionally provisioned, active admin exists.
5. Review the additive migration and approve any development-branch schema
   change before executing it. Run the app against that dev branch and retest.
6. Obtain team approval for cross-service authorization before claiming the
   whole system enforces the same user login.

## Local workspace synchronization

The merged PR updated `main`. The developer's Windows workspace may contain
uncommitted UI changes from before the merge. **Check `git status` and preserve
those changes before pulling/rebasing; do not force-reset the working tree.**

## Acceptance decision

- **PASS:** code compilation, isolated Laravel functionality and PostgreSQL
  compatibility, subject to current CI status.
- **PENDING:** verification against the owner's actual Neon development branch.
- **PENDING:** API-level single sign-on across all three backends.
- **PENDING:** end-to-end data operations in the team Monthly Fees / Allocations
  / Kitchens backends using real development data.
