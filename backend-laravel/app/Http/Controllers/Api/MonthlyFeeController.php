<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateMonthlyFeeRequest;
use App\Http\Requests\PreviewMonthlyFeeRequest;
use App\Services\MonthlyFeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Exception;

class MonthlyFeeController extends Controller
{
    protected MonthlyFeeService $feeService;

    public function __construct(MonthlyFeeService $feeService)
    {
        $this->feeService = $feeService;
    }

    /**
     * Display a listing of monthly fees with filters and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $data = $this->feeService->listFees($request->all());
            return response()->json($data);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Error retrieving monthly fees: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Preview fee generation for a given billing period.
     */
    public function preview(PreviewMonthlyFeeRequest $request): JsonResponse
    {
        try {
            $period = $request->input('period');
            $preview = $this->feeService->previewGeneration($period);
            return response()->json($preview);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Error generating fee preview: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Generate monthly fee records for eligible residents.
     */
    public function generate(GenerateMonthlyFeeRequest $request): JsonResponse
    {
        try {
            $period = $request->input('billing_period');
            $amount = (float) $request->input('amount');
            $dueDate = $request->input('due_date');
            $remarks = $request->input('remarks');

            $result = $this->feeService->generateFees($period, $amount, $dueDate, $remarks);

            return response()->json($result, 201);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Failed to generate monthly fees: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Display the specified monthly fee record details.
     */
    public function show(int $id): JsonResponse
    {
        try {
            $fee = $this->feeService->getFeeDetails($id);

            if (!$fee) {
                return response()->json([
                    'message' => 'Monthly fee record not found.',
                ], 404);
            }

            return response()->json(['fee' => $fee]);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Error retrieving fee details: ' . $e->getMessage(),
            ], 500);
        }
    }
}
