<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentTransaction;
use App\Services\OnlinePayments\Money;
use App\Services\OnlinePayments\OnlinePaymentException;
use App\Services\OnlinePayments\OnlinePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PayMongo SANDBOX checkout for the signed-in customer. Every action is limited to the customer's
 * own booking and to allow-listed test users; anyone else gets a plain 404.
 */
class OnlinePaymentController extends Controller
{
    public function __construct(protected OnlinePaymentService $payments)
    {
    }

    public function checkout(Request $request, string $code): JsonResponse
    {
        // Only the payment channel is read from the client. The amount is rebuilt on the server.
        $validated = $request->validate([
            'method' => 'nullable|string|max:20',
        ]);

        return $this->respond(function () use ($request, $code, $validated) {
            $result = $this->payments->initiate($request->user(), $code, $validated['method'] ?? 'gcash');
            $transaction = $result['transaction'];

            return response()->json([
                'success' => true,
                'data' => $this->present($transaction, includeLink: true),
            ], $result['created'] ? 201 : 200);
        });
    }

    public function show(Request $request, string $code): JsonResponse
    {
        return $this->respond(fn () => response()->json([
            'success' => true,
            'data' => $this->payments->statusFor($request->user(), $code),
        ]));
    }

    public function cancel(Request $request, string $code): JsonResponse
    {
        return $this->respond(fn () => response()->json([
            'success' => true,
            'data' => $this->present($this->payments->cancel($request->user(), $code)),
        ]));
    }

    /** @param  callable(): JsonResponse  $action */
    private function respond(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (OnlinePaymentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage(), 'reason' => $e->reason], $e->status);
        }
    }

    /** Never includes provider ids, secrets, or the link of a finished session. */
    private function present(PaymentTransaction $transaction, bool $includeLink = false): array
    {
        return [
            'reference' => $transaction->reference,
            'status' => $transaction->status,
            'method' => $transaction->method,
            'amount' => Money::centavosToPesos($transaction->amount_centavos),
            'currency' => $transaction->currency,
            'expires_at' => $transaction->expires_at?->toIso8601String(),
            'checkout_url' => $includeLink && $transaction->isOpen() ? $transaction->checkout_url : null,
        ];
    }
}
