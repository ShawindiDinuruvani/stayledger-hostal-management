<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;

class PaymentService
{
    public function getBalance(int $feeId): array
    {
        $fee = DB::table('monthly_fees')->where('fee_id', $feeId)->first();
        if (!$fee) {
            throw new RuntimeException('Monthly fee not found.');
        }
        $totalPaid = (float) DB::table('payments')->where('fee_id', $feeId)->sum('amount');
        $outstanding = max(0.0, (float) $fee->amount_due - $totalPaid);
        return [
            'fee_id' => $feeId,
            'amount_due' => (float) $fee->amount_due,
            'total_paid' => $totalPaid,
            'outstanding_balance' => $outstanding,
            'status' => $fee->status,
        ];
    }

    public function recordPayment(int $feeId, array $data, $recordedBy): array
    {
        return DB::transaction(function () use ($feeId, $data, $recordedBy) {
            $fee = DB::table('monthly_fees')->where('fee_id', $feeId)->lockForUpdate()->first();
            if (!$fee) {
                throw new RuntimeException('Monthly fee not found.');
            }
            if ($fee->status === 'PAID') {
                throw new InvalidArgumentException('A PAID fee cannot accept additional payments.');
            }
            $amount = (float) $data['amount'];
            if ($amount <= 0) {
                throw new InvalidArgumentException('Payment amount must be greater than zero.');
            }
            $paidDate = $data['paid_date'];
            if ($paidDate > date('Y-m-d')) {
                throw new InvalidArgumentException('Payment date cannot be in the future.');
            }
            $createdDate = substr((string) ($fee->created_at ?? date('Y-m-d')), 0, 10);
            if ($paidDate < $createdDate) {
                throw new InvalidArgumentException('Payment date cannot be earlier than the fee creation date.');
            }
            $totalPaid = (float) DB::table('payments')->where('fee_id', $feeId)->sum('amount');
            $balance = (float) $fee->amount_due - $totalPaid;
            if ($amount > $balance) {
                throw new InvalidArgumentException('Payment amount exceeds the outstanding balance.');
            }

            if (DB::getDriverName() === 'pgsql') {
                DB::statement(
                    'CALL sp_record_payment(?, ?, ?, ?, ?, ?, NULL, NULL)',
                    [$feeId, $amount, $paidDate, strtoupper($data['method']), $data['reference_no'] ?? null, $recordedBy]
                );
                $payment = DB::table('payments')->where('fee_id', $feeId)->orderByDesc('payment_id')->first();
            } else {
                $paymentId = DB::table('payments')->insertGetId([
                    'payment_code' => $this->newCode('PAY'),
                    'fee_id' => $feeId,
                    'amount' => $amount,
                    'paid_date' => $paidDate,
                    'method' => strtoupper($data['method']),
                    'reference_no' => $data['reference_no'] ?? null,
                    'payment_type' => 'PAYMENT',
                    'reverses_payment_id' => null,
                    'reason' => null,
                    'recorded_by' => $recordedBy,
                    'created_at' => now(),
                ], 'payment_id');
                $payment = DB::table('payments')->where('payment_id', $paymentId)->first();
            }

            $newTotal = (float) DB::table('payments')->where('fee_id', $feeId)->sum('amount');
            $status = $newTotal >= (float) $fee->amount_due ? 'PAID' : ($newTotal > 0 ? 'PARTIALLY_PAID' : 'UNPAID');
            DB::table('monthly_fees')->where('fee_id', $feeId)->update(['status' => $status, 'updated_at' => now()]);
            return ['payment' => $payment, 'balance' => $this->getBalance($feeId)];
        });
    }

    public function listForFee(int $feeId, array $filters = []): array
    {
        $balance = $this->getBalance($feeId);
        $query = DB::table('vw_payment_history')->where('fee_id', $feeId);
        $this->applyFilters($query, $filters);
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 15)));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $total = (clone $query)->count();
        $items = $query->orderByDesc('payment_id')->forPage($page, $perPage)->get();
        return [
            'payments' => $items,
            'balance' => $balance,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ];
    }

    public function history(array $filters = []): array
    {
        $query = DB::table('vw_payment_history');
        $this->applyFilters($query, $filters);
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 15)));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $total = (clone $query)->count();
        $items = $query->orderByDesc('payment_id')->forPage($page, $perPage)->get();
        return [
            'payments' => $items,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ];
    }

    private function applyFilters($query, array $filters): void
    {
        if (!empty($filters['date_from'])) $query->where('paid_date', '>=', $filters['date_from']);
        if (!empty($filters['date_to'])) $query->where('paid_date', '<=', $filters['date_to']);
        if (!empty($filters['method'])) $query->where('method', strtoupper($filters['method']));
        if (!empty($filters['payment_type'])) $query->where('payment_type', strtoupper($filters['payment_type']));
        if (!empty($filters['fee_id'])) $query->where('fee_id', (int) $filters['fee_id']);
        if (!empty($filters['search'])) {
            $term = '%' . trim($filters['search']) . '%';
            $like = DB::getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';
            $query->where(function ($q) use ($term, $like) {
                $q->where('payment_code', $like, $term)
                  ->orWhere('resident_name', $like, $term)
                  ->orWhere('reference_no', $like, $term);
            });
        }
    }

    public function reversePayment(int $paymentId, string $reason, $recordedBy): array
    {
        return DB::transaction(function () use ($paymentId, $reason, $recordedBy) {
            $original = DB::table('payments')->where('payment_id', $paymentId)->lockForUpdate()->first();
            if (!$original) throw new RuntimeException('Payment not found.');
            if ($original->payment_type === 'REVERSAL') throw new InvalidArgumentException('A reversal record cannot be reversed.');
            if (trim($reason) === '') throw new InvalidArgumentException('A reversal reason is required.');
            if (DB::table('payments')->where('reverses_payment_id', $paymentId)->exists()) {
                throw new InvalidArgumentException('This payment has already been reversed.');
            }
            $fee = DB::table('monthly_fees')->where('fee_id', $original->fee_id)->lockForUpdate()->first();
            $total = (float) DB::table('payments')->where('fee_id', $original->fee_id)->sum('amount');
            if ($total - (float) $original->amount < 0) {
                throw new InvalidArgumentException('Reversal cannot make the total paid amount negative.');
            }

            if (DB::getDriverName() === 'pgsql') {
                DB::statement('CALL sp_reverse_payment(?, ?, ?, NULL)', [$paymentId, $recordedBy, $reason]);
                $reversal = DB::table('payments')->where('reverses_payment_id', $paymentId)->first();
            } else {
                $reversalId = DB::table('payments')->insertGetId([
                    'payment_code' => $this->newCode('REV'),
                    'fee_id' => $original->fee_id,
                    'amount' => -(float) $original->amount,
                    'paid_date' => $original->paid_date,
                    'method' => $original->method,
                    'reference_no' => $original->reference_no,
                    'payment_type' => 'REVERSAL',
                    'reverses_payment_id' => $paymentId,
                    'reason' => $reason,
                    'recorded_by' => $recordedBy,
                    'created_at' => now(),
                ], 'payment_id');
                $reversal = DB::table('payments')->where('payment_id', $reversalId)->first();
            }

            $newTotal = (float) DB::table('payments')->where('fee_id', $original->fee_id)->sum('amount');
            $status = $newTotal >= (float) $fee->amount_due ? 'PAID' : ($newTotal > 0 ? 'PARTIALLY_PAID' : 'UNPAID');
            DB::table('monthly_fees')->where('fee_id', $original->fee_id)->update(['status' => $status, 'updated_at' => now()]);
            return ['reversal' => $reversal, 'balance' => $this->getBalance($original->fee_id)];
        });
    }

    public function correctPayment(int $paymentId, array $data, $recordedBy): array
    {
        return DB::transaction(function () use ($paymentId, $data, $recordedBy) {
            $reversal = $this->reversePayment($paymentId, $data['reason'], $recordedBy);
            $original = DB::table('payments')->where('payment_id', $paymentId)->first();
            $replacement = $this->recordPayment($original->fee_id, $data, $recordedBy);
            return ['reversal' => $reversal['reversal'], 'replacement' => $replacement['payment'], 'balance' => $replacement['balance']];
        });
    }

    private function newCode(string $prefix): string
    {
        return $prefix . '-' . strtoupper(substr(bin2hex(random_bytes(8)), 0, 16));
    }
}
