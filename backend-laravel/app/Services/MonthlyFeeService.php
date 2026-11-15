<?php

namespace App\Services;

use App\Models\MonthlyFee;
use App\Models\Resident;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Pagination\LengthAwarePaginator;
use Exception;

class MonthlyFeeService
{
    /**
     * List monthly fees with filters, search, pagination, and honest payment integration metadata.
     */
    public function listFees(array $filters): array
    {
        $period = !empty($filters['period']) ? trim($filters['period']) : null;
        $search = !empty($filters['search']) ? trim($filters['search']) : null;
        $status = !empty($filters['status']) && $filters['status'] !== 'All' ? trim($filters['status']) : null;
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 15)));

        // If monthly_fees table does not exist yet
        if (!Schema::hasTable('monthly_fees')) {
            return [
                'fees' => [],
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => 0,
                    'total_pages' => 0,
                ],
                'summary' => [
                    'total_records' => 0,
                    'total_billed' => 0.00,
                ],
                'notice' => 'The monthly_fees table is not yet migrated in the active database.',
            ];
        }

        $hasPaymentsTable = Schema::hasTable('payments');

        $query = DB::table('monthly_fees as mf')
            ->join('residents as res', 'mf.resident_id', '=', 'res.resident_id');

        if ($period) {
            $query->where('mf.billing_period', $period);
        }

        if ($status) {
            $query->where('mf.status', $status);
        }

        if ($search) {
            $likeOp = DB::getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';
            $query->where(function ($q) use ($search, $likeOp) {
                $term = "%{$search}%";
                $q->where('res.full_name', $likeOp, $term)
                  ->orWhere('res.resident_code', $likeOp, $term)
                  ->orWhere('mf.fee_code', $likeOp, $term);
            });
        }

        $totalRecords = $query->count();
        $totalBilled = (float) (clone $query)->sum('mf.amount_due');

        // Fetch paginated results with active room allocation details if present
        $fees = $query
            ->select([
                'mf.fee_id',
                'mf.fee_code',
                'mf.billing_period',
                'mf.amount_due',
                'mf.due_date',
                'mf.status as fee_status',
                'mf.remarks',
                'mf.created_at',
                'mf.updated_at',
                'res.resident_id',
                'res.resident_code',
                'res.full_name as resident_name',
                'res.email as resident_email',
                'res.phone as resident_phone',
            ])
            ->orderBy('mf.billing_period', 'desc')
            ->orderBy('res.full_name', 'asc')
            ->forPage($page, $perPage)
            ->get();

        // Enrich with room allocation details and honest payment status
        $enrichedFees = $fees->map(function ($fee) use ($hasPaymentsTable) {
            // Find active room allocation if room_allocations table exists
            $roomInfo = null;
            if (Schema::hasTable('room_allocations') && Schema::hasTable('rooms') && Schema::hasTable('floors')) {
                $roomInfo = DB::table('room_allocations as ra')
                    ->join('rooms as rm', 'ra.room_id', '=', 'rm.room_id')
                    ->join('floors as fl', 'rm.floor_id', '=', 'fl.floor_id')
                    ->where('ra.resident_id', $fee->resident_id)
                    ->where('ra.status', 'Active')
                    ->orderBy('ra.allocation_id', 'desc')
                    ->select('rm.room_number', 'fl.floor_name')
                    ->first();
            }

            $fee->room_number = $roomInfo ? $roomInfo->room_number : null;
            $fee->floor_name = $roomInfo ? $roomInfo->floor_name : null;

            // SECTION 6 REQUIREMENT: Honest Payment Integration Handling
            // Missing payment integration does not prove that no payment exists.
            if ($hasPaymentsTable) {
                $paymentSum = DB::table('payments')
                    ->where('fee_id', $fee->fee_id)
                    ->sum('amount');

                $fee->payment_status = $paymentSum >= $fee->amount_due ? 'PAID' : ($paymentSum > 0 ? 'PARTIALLY_PAID' : 'UNPAID');
                $fee->paid_amount = (float) $paymentSum;
                $fee->balance_amount = max(0.0, (float) $fee->amount_due - (float) $paymentSum);
                $fee->payment_available = true;
            } else {
                // Payment integration is unavailable / unlinked
                $fee->payment_status = 'UNAVAILABLE';
                $fee->paid_amount = null;
                $fee->balance_amount = null;
                $fee->payment_available = false;
                $fee->payment_notice = 'Payment information unavailable.';
            }

            return $fee;
        });

        $totalPages = $totalRecords > 0 ? (int) ceil($totalRecords / $perPage) : 0;

        return [
            'fees' => $enrichedFees,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $totalRecords,
                'total_pages' => $totalPages,
            ],
            'summary' => [
                'total_records' => $totalRecords,
                'total_billed' => $totalBilled,
            ],
        ];
    }

    /**
     * Resolve billing eligibility for all residents during a given billing period.
     * Shared by both previewGeneration() and generateFees() to guarantee identical eligibility rules.
     *
     * Rules enforced:
     * 1. Historical eligibility: A resident's current status ('ACTIVE' or 'INACTIVE') does NOT exclude
     *    them if they had a valid room allocation stay during the historical billing period.
     * 2. Full-month requirement: Full fee generation requires a stay covering the complete calendar month
     *    (min_start_date <= period_start AND (open_ended OR max_end_date >= period_end)).
     * 3. Server-side partial-stay blocking: Any resident with a partial-month stay (arrival after period_start
     *    or departure before period_end) is marked BLOCKED_POLICY_UNCONFIRMED and strictly blocked from
     *    fee generation pending confirmed hostel prorating policy.
     */
    public function resolvePeriodEligibility(string $billingPeriod): array
    {
        if (!Schema::hasTable('residents')) {
            throw new Exception('The residents table does not exist in the active database.');
        }

        $periodStart = "{$billingPeriod}-01";
        $periodEnd = date('Y-m-t', strtotime($periodStart));

        // Existing fees for idempotency checking
        $existingFees = [];
        if (Schema::hasTable('monthly_fees')) {
            $existingFees = DB::table('monthly_fees')
                ->where('billing_period', $billingPeriod)
                ->pluck('fee_code', 'resident_id')
                ->toArray();
        }

        $eligible = [];
        $alreadyBilled = [];
        $blocked = [];
        $previewList = [];

        $hasAllocations = Schema::hasTable('room_allocations') && Schema::hasTable('rooms') && Schema::hasTable('floors');

        if ($hasAllocations) {
            // Find all allocations overlapping this billing period
            // Does NOT filter by residents.status = 'ACTIVE' so historical stays of currently INACTIVE residents are correctly captured
            $allocations = DB::table('room_allocations as ra')
                ->join('residents as res', 'ra.resident_id', '=', 'res.resident_id')
                ->join('rooms as rm', 'ra.room_id', '=', 'rm.room_id')
                ->join('floors as fl', 'rm.floor_id', '=', 'fl.floor_id')
                ->where('ra.start_date', '<=', $periodEnd)
                ->where(function ($q) use ($periodStart) {
                    $q->whereNull('ra.end_date')
                      ->orWhere('ra.end_date', '>=', $periodStart);
                })
                ->select([
                    'res.resident_id',
                    'res.resident_code',
                    'res.full_name',
                    'res.email',
                    'res.status as resident_status',
                    'rm.room_number',
                    'fl.floor_name',
                    'ra.start_date',
                    'ra.end_date',
                    'ra.status as allocation_status',
                    'ra.allocation_id',
                ])
                ->orderBy('res.resident_id', 'asc')
                ->orderBy('ra.allocation_id', 'desc')
                ->get();

            // Group by resident to handle transfers or contiguous stays
            $grouped = $allocations->groupBy('resident_id');

            foreach ($grouped as $residentId => $allocGroup) {
                $first = $allocGroup->first();
                $minStartDate = $allocGroup->min('start_date');
                $isOpenEnded = $allocGroup->contains(fn($a) => is_null($a->end_date));
                $maxEndDate = $isOpenEnded ? null : $allocGroup->max('end_date');

                // Room info from latest allocation
                $roomNumber = $first->room_number ?? 'Unallocated';
                $floorName = $first->floor_name ?? '—';

                // Check full-month stay coverage
                $isFullMonth = ($minStartDate <= $periodStart) && ($isOpenEnded || $maxEndDate >= $periodEnd);

                $resRecord = [
                    'resident_id' => $first->resident_id,
                    'resident_code' => $first->resident_code,
                    'full_name' => $first->full_name,
                    'email' => $first->email,
                    'resident_status' => $first->resident_status,
                    'room_number' => $roomNumber,
                    'floor_name' => $floorName,
                    'min_start_date' => $minStartDate,
                    'max_end_date' => $maxEndDate,
                    'is_open_ended' => $isOpenEnded,
                ];

                if (!$isFullMonth) {
                    // PARTIAL-MONTH STAY: Strictly BLOCKED server-side pending confirmed hostel prorating policy
                    $resRecord['status'] = 'BLOCKED_POLICY_UNCONFIRMED';
                    $resRecord['fee_code'] = null;
                    $resRecord['block_reason'] = "Partial-month stay detected ({$minStartDate} to " . ($isOpenEnded ? 'present' : $maxEndDate) . "). Prorating policy unconfirmed by hostel management; fee generation blocked server-side.";
                    $blocked[] = $resRecord;
                    $previewList[] = $resRecord;
                } elseif (isset($existingFees[$residentId])) {
                    // ALREADY BILLED
                    $resRecord['status'] = 'ALREADY_BILLED';
                    $resRecord['fee_code'] = $existingFees[$residentId];
                    $alreadyBilled[] = $resRecord;
                    $previewList[] = $resRecord;
                } else {
                    // FULL MONTH STAY & NOT BILLED: ELIGIBLE
                    $resRecord['status'] = 'ELIGIBLE';
                    $resRecord['fee_code'] = null;
                    $eligible[] = $resRecord;
                    $previewList[] = $resRecord;
                }
            }
        } else {
            // Fallback when room_allocations is not available: use active residents
            $residents = DB::table('residents')
                ->where('status', 'ACTIVE')
                ->orderBy('full_name', 'asc')
                ->get();

            foreach ($residents as $res) {
                $resRecord = [
                    'resident_id' => $res->resident_id,
                    'resident_code' => $res->resident_code,
                    'full_name' => $res->full_name,
                    'email' => $res->email,
                    'resident_status' => $res->status,
                    'room_number' => 'Unallocated',
                    'floor_name' => '—',
                ];

                if (isset($existingFees[$res->resident_id])) {
                    $resRecord['status'] = 'ALREADY_BILLED';
                    $resRecord['fee_code'] = $existingFees[$res->resident_id];
                    $alreadyBilled[] = $resRecord;
                    $previewList[] = $resRecord;
                } else {
                    $resRecord['status'] = 'ELIGIBLE';
                    $resRecord['fee_code'] = null;
                    $eligible[] = $resRecord;
                    $previewList[] = $resRecord;
                }
            }
        }

        return [
            'period' => $billingPeriod,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'eligible' => $eligible,
            'already_billed' => $alreadyBilled,
            'blocked' => $blocked,
            'preview_list' => $previewList,
        ];
    }

    /**
     * Preview fee generation for a given billing period.
     */
    public function previewGeneration(string $period): array
    {
        $resolved = $this->resolvePeriodEligibility($period);

        return [
            'billing_period' => $period,
            'total_active_residents' => count($resolved['preview_list']),
            'eligible_count' => count($resolved['eligible']),
            'already_billed_count' => count($resolved['already_billed']),
            'blocked_count' => count($resolved['blocked']),
            'preview_list' => $resolved['preview_list'],
        ];
    }

    /**
     * Generate monthly fees within an explicit Laravel-managed transaction,
     * invoking the PostgreSQL stored procedure when available with fallback to atomic query.
     */
    public function generateFees(string $billingPeriod, float $amount, string $dueDate, ?string $remarks = null): array
    {
        return DB::transaction(function () use ($billingPeriod, $amount, $dueDate, $remarks) {
            $createdCount = 0;
            $skippedCount = 0;
            $totalEligible = 0;
            $blockedCount = 0;
            $usedStoredProcedure = false;

            // Attempt invoking the PostgreSQL stored routine if present
            try {
                // In PostgreSQL, fn_generate_monthly_fees returns TABLE (created_count, skipped_count, total_eligible, blocked_count)
                $result = DB::select('SELECT * FROM fn_generate_monthly_fees(?, ?, ?)', [
                    $billingPeriod,
                    $amount,
                    $dueDate,
                ]);

                if (!empty($result)) {
                    $row = (array) $result[0];
                    $createdCount = (int) $row['created_count'];
                    $skippedCount = (int) $row['skipped_count'];
                    $totalEligible = (int) $row['total_eligible'];
                    $blockedCount = (int) ($row['blocked_count'] ?? 0);
                    $usedStoredProcedure = true;
                }
            } catch (Exception $routineException) {
                // Fallback to transactional Eloquent/QueryBuilder logic if stored routine is not yet deployed on DB instance
                $usedStoredProcedure = false;
            }

            if (!$usedStoredProcedure) {
                if (!Schema::hasTable('monthly_fees')) {
                    throw new Exception('The monthly_fees table does not exist. Run the migration first.');
                }

                // Resolve eligibility using the EXACT same rules as preview
                $resolved = $this->resolvePeriodEligibility($billingPeriod);
                $totalEligible = count($resolved['eligible']) + count($resolved['already_billed']);
                $skippedCount = count($resolved['already_billed']);
                $blockedCount = count($resolved['blocked']);

                $cleanPeriod = str_replace('-', '', $billingPeriod);
                $feeRemarks = !empty($remarks) ? $remarks : ('System generated fee for ' . $billingPeriod);

                // Only generate fees for confirmed eligible residents (full month stays)
                foreach ($resolved['eligible'] as $res) {
                    $feeCode = 'FEE-' . $cleanPeriod . '-' . str_pad((string) $res['resident_id'], 4, '0', STR_PAD_LEFT);

                    try {
                        DB::table('monthly_fees')->insert([
                            'fee_code' => $feeCode,
                            'resident_id' => $res['resident_id'],
                            'billing_period' => $billingPeriod,
                            'amount_due' => $amount,
                            'due_date' => $dueDate,
                            'status' => 'UNPAID',
                            'remarks' => $feeRemarks,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $createdCount++;
                    } catch (Exception $e) {
                        // Unique constraint catches race conditions
                        $skippedCount++;
                    }
                }
            }

            $message = "Fee generation completed: {$createdCount} created, {$skippedCount} skipped (already billed)";
            if ($blockedCount > 0) {
                $message .= ", {$blockedCount} blocked (partial stays pending prorating policy).";
            } else {
                $message .= ".";
            }

            return [
                'success' => true,
                'message' => $message,
                'billing_period' => $billingPeriod,
                'amount' => $amount,
                'due_date' => $dueDate,
                'created_count' => $createdCount,
                'skipped_count' => $skippedCount,
                'total_eligible' => $totalEligible,
                'blocked_count' => $blockedCount,
                'used_stored_procedure' => $usedStoredProcedure,
            ];
        });
    }

    /**
     * Retrieve details for a single monthly fee record.
     */
    public function getFeeDetails(int $feeId): ?object
    {
        if (!Schema::hasTable('monthly_fees')) {
            return null;
        }

        $fee = DB::table('monthly_fees as mf')
            ->join('residents as res', 'mf.resident_id', '=', 'res.resident_id')
            ->where('mf.fee_id', $feeId)
            ->select([
                'mf.fee_id',
                'mf.fee_code',
                'mf.billing_period',
                'mf.amount_due',
                'mf.due_date',
                'mf.status as fee_status',
                'mf.remarks',
                'mf.created_at',
                'mf.updated_at',
                'res.resident_id',
                'res.resident_code',
                'res.full_name as resident_name',
                'res.email as resident_email',
                'res.phone as resident_phone',
            ])
            ->first();

        if (!$fee) {
            return null;
        }

        // Room details
        $roomInfo = null;
        if (Schema::hasTable('room_allocations') && Schema::hasTable('rooms') && Schema::hasTable('floors')) {
            $roomInfo = DB::table('room_allocations as ra')
                ->join('rooms as rm', 'ra.room_id', '=', 'rm.room_id')
                ->join('floors as fl', 'rm.floor_id', '=', 'fl.floor_id')
                ->where('ra.resident_id', $fee->resident_id)
                ->where('ra.status', 'Active')
                ->select('rm.room_number', 'fl.floor_name')
                ->first();
        }

        $fee->room_number = $roomInfo ? $roomInfo->room_number : null;
        $fee->floor_name = $roomInfo ? $roomInfo->floor_name : null;

        // Payment details check
        if (Schema::hasTable('payments')) {
            $paymentSum = DB::table('payments')
                ->where('fee_id', $fee->fee_id)
                ->sum('amount');

            $fee->payment_status = $paymentSum >= $fee->amount_due ? 'PAID' : ($paymentSum > 0 ? 'PARTIALLY_PAID' : 'UNPAID');
            $fee->paid_amount = (float) $paymentSum;
            $fee->balance_amount = max(0.0, (float) $fee->amount_due - (float) $paymentSum);
            $fee->payment_available = true;
        } else {
            $fee->payment_status = 'UNAVAILABLE';
            $fee->paid_amount = null;
            $fee->balance_amount = null;
            $fee->payment_available = false;
            $fee->payment_notice = 'Payment information unavailable.';
        }

        return $fee;
    }
}
