# Payments Module

## Database design

The `payments` table stores immutable PAYMENT and REVERSAL records. Each row references `monthly_fees.fee_id` with `ON DELETE RESTRICT`; `reverses_payment_id` is a unique self-reference and may point to an original payment only once. `recorded_by` records the authenticated StayLedger user.

Key constraints:
- PAYMENT amounts must be positive.
- REVERSAL amounts must be negative.
- Payment dates cannot be future dated.
- Fee references cannot be deleted while payments exist.
- Updating or deleting a payment is rejected by `trg_payments_immutable`.

Corrections are audit-safe: the original payment is reversed with a negative REVERSAL row, then a new PAYMENT row is inserted. Both operations run in one Laravel database transaction.

## PostgreSQL objects

- `fn_fee_balance(fee_id)` returns `amount_due - SUM(payments.amount)`.
- `vw_payment_history` joins payment, fee, resident, room, and recorded-user details.
- `vw_fee_balance` shows fee, resident, room, amount due, total paid, outstanding balance, and status.
- `sp_record_payment` locks the fee, validates and inserts a payment, and updates fee status.
- `sp_reverse_payment` locks the original fee, validates double reversal and negative totals, inserts a reversal, and updates status.

All procedures use no internal COMMIT or ROLLBACK. Laravel opens and owns the database transaction.

## API endpoints

All routes use the existing `EnsureAuthenticated` middleware.

- `POST /api/fees/{id}/payments` - record a payment
- `GET /api/fees/{id}/payments` - payments for a fee
- `GET /api/payments` - filtered history
- `GET /api/fees/{id}/balance` - current fee balance
- `POST /api/payments/{id}/reverse` - reverse a payment
- `POST /api/payments/{id}/correct` - reverse and replace atomically

History filters: `date_from`, `date_to`, `method`, `payment_type`, `fee_id`, `search`, `page`, and `per_page`.

## Validation

Laravel Form Requests validate positive amounts, method values, future dates, and required reversal/correction reasons. Database constraints and PostgreSQL procedures also enforce payment rules, outstanding limits, fee state, date, and reversal uniqueness.

## Migration and standalone SQL

Run from the Laravel application:

```powershell
cd backend
php artisan migrate
```

To import the grading schema directly into PostgreSQL:

```powershell
psql "$env:DATABASE_URL_UNPOOLED" -f database/stayledger_payments_schema.sql
```

The standalone file assumes that `monthly_fees`, `residents`, `room_allocations`, `rooms`, `floors`, and `users` already exist.

## Tests

```powershell
cd backend
php artisan test --filter=PaymentApiTest
php artisan test --filter=MonthlyFeeApiTest
```

## Demo data

```sql
-- Replace the resident code below only with an existing resident code.
INSERT INTO monthly_fees (fee_code,resident_id,billing_period,amount_due,due_date,status,created_at,updated_at)
SELECT 'FEE-DEMO-PARTIAL', resident_id, '2026-11', 18000, '2026-11-15', 'UNPAID', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM residents WHERE resident_code='RES-001'
ON CONFLICT (resident_id,billing_period) DO NOTHING;

INSERT INTO payments (payment_code,fee_id,amount,paid_date,method,payment_type,recorded_by)
SELECT 'PAY-DEMO-PARTIAL', mf.fee_id, 9000, CURRENT_DATE, 'CASH', 'PAYMENT', u.id
FROM monthly_fees mf JOIN users u ON u.email='warden@stayledger.local'
WHERE mf.fee_code='FEE-DEMO-PARTIAL';
UPDATE monthly_fees SET status='PARTIALLY_PAID' WHERE fee_code='FEE-DEMO-PARTIAL';

-- Fully paid example
INSERT INTO monthly_fees (fee_code,resident_id,billing_period,amount_due,due_date,status,created_at,updated_at)
SELECT 'FEE-DEMO-FULL', resident_id, '2026-12', 18000, '2026-12-15', 'UNPAID', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM residents WHERE resident_code='RES-001'
ON CONFLICT (resident_id,billing_period) DO NOTHING;

INSERT INTO payments (payment_code,fee_id,amount,paid_date,method,payment_type,recorded_by)
SELECT 'PAY-DEMO-FULL', mf.fee_id, mf.amount_due, CURRENT_DATE, 'BANK', 'PAYMENT', u.id
FROM monthly_fees mf JOIN users u ON u.email='warden@stayledger.local'
WHERE mf.fee_code='FEE-DEMO-FULL';
UPDATE monthly_fees SET status='PAID' WHERE fee_code='FEE-DEMO-FULL';
```

For a corrected payment, insert the original, reverse it by creating `amount = -original_amount` with `reverses_payment_id=original.payment_id`, then insert the replacement. The application endpoints perform this pair atomically.

## Monthly fee integration

`MonthlyFeeService` uses `fn_fee_balance()` for PostgreSQL payment balances when that function exists. If the function is unavailable, it uses the same direct payment sum fallback. `DashboardReportController.monthly_income` should continue summing `payments.amount` by `paid_date`; reversals are negative and therefore automatically reduce income. Do not change `payments.paid_date` or `payments.amount`.
