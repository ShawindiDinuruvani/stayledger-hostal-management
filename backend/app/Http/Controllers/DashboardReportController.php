<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardReportController extends Controller
{
    public function summary(Request $request)
    {
        $from = $request->query('from', '2026-11-01');
        $to   = $request->query('to', '2026-11-30');

        $totalCapacity = (int) DB::table('rooms')->sum('total_beds');
        $occupiedBeds  = (int) DB::table('allocations')->where('status', 'active')->count();
        $occupancyRate = $totalCapacity > 0
            ? round(($occupiedBeds / $totalCapacity) * 100, 1)
            : 0;

        $unpaidCount  = (int) DB::table('monthly_fees')->where('status', 'unpaid')->count();
        $unpaidAmount = (float) DB::table('monthly_fees')->where('status', 'unpaid')->sum('amount');

        $monthlyIncome = (float) DB::table('payments')
            ->whereBetween('paid_date', [$from, $to])
            ->sum('amount');

        $monthlyExpense = (float) DB::table('hostel_expenses')
            ->whereBetween('expense_date', [$from, $to])
            ->sum('amount');

        return response()->json([
            'occupancy' => [
                'occupied_beds'  => $occupiedBeds,
                'total_capacity' => $totalCapacity,
                'occupancy_rate' => $occupancyRate,
            ],
            'unpaid_fees' => [
                'unpaid_count'  => $unpaidCount,
                'unpaid_amount' => $unpaidAmount,
            ],
            'financials' => [
                'monthly_income'  => $monthlyIncome,
                'monthly_expense' => $monthlyExpense,
                'net_reconciled'  => $monthlyIncome - $monthlyExpense,
            ],
        ]);
    }

    public function monthly(Request $request)
    {
        $fromYm = substr($request->query('from', '2026-11-01'), 0, 7);
        $toYm   = substr($request->query('to', '2026-11-30'), 0, 7);

        $rows = DB::table('view_monthly_financial_summary')
            ->whereBetween('period_ym', [$fromYm, $toYm])
            ->orderBy('period_ym')
            ->get();

        $totalIncome  = (float) $rows->sum('total_income');
        $totalExpense = (float) $rows->sum('total_expense');

        return response()->json([
            'rows' => $rows,
            'reconciled' => [
                'total_income'  => $totalIncome,
                'total_expense' => $totalExpense,
                'net_balance'   => $totalIncome - $totalExpense,
            ],
        ]);
    }

    public function explain(Request $request)
    {
        $from = $request->query('from', '2026-11-01');
        $to   = $request->query('to', '2026-11-30');

        $sql  = "SELECT COALESCE(SUM(amount), 0) AS total FROM hostel_expenses WHERE expense_date BETWEEN ? AND ?";
        $plan = DB::select("EXPLAIN (FORMAT JSON) {$sql}", [$from, $to]);

        return response()->json([
            'driver' => DB::getDriverName(),
            'query'  => $sql,
            'plan'   => $plan,
        ]);
    }
}