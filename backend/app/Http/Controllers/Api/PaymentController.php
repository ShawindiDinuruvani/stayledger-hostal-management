<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CorrectPaymentRequest;
use App\Http\Requests\RecordPaymentRequest;
use App\Http\Requests\ReversePaymentRequest;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class PaymentController extends Controller
{
    public function __construct(private PaymentService $service) {}

    private function recordedBy(Request $request): ?int
    {
        if ($request->attributes->has('stayledger_user')) {
            $user = $request->attributes->get('stayledger_user');
            return is_numeric($user['id'] ?? null) ? (int) $user['id'] : null;
        }
        $user = $request->user();
        return $user ? (int) $user->id : null;
    }

    public function record(RecordPaymentRequest $request, int $id): JsonResponse
    {
        try {
            $result = $this->service->recordPayment($id, $request->validated(), $this->recordedBy($request));
            return response()->json(['success' => true, 'payment' => $result['payment'], 'balance' => $result['balance']], 201);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function forFee(Request $request, int $id): JsonResponse
    {
        try {
            return response()->json($this->service->listForFee($id, $request->all()));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }

    public function history(Request $request): JsonResponse
    {
        return response()->json($this->service->history($request->all()));
    }

    public function balance(int $id): JsonResponse
    {
        try {
            return response()->json($this->service->getBalance($id));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }

    public function reverse(ReversePaymentRequest $request, int $id): JsonResponse
    {
        try {
            $result = $this->service->reversePayment($id, $request->validated('reason'), $this->recordedBy($request));
            return response()->json(['success' => true, 'reversal' => $result['reversal'], 'balance' => $result['balance']]);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function correct(CorrectPaymentRequest $request, int $id): JsonResponse
    {
        try {
            $result = $this->service->correctPayment($id, $request->validated(), $this->recordedBy($request));
            return response()->json(['success' => true, 'reversal' => $result['reversal'], 'replacement' => $result['replacement'], 'balance' => $result['balance']]);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
