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
 * own booking and to active Customer accounts; anyone else gets a plain 404.
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
            'bank' => 'nullable|string|max:20',
        ]);

        return $this->respond(function () use ($request, $code, $validated) {
            $result = $this->payments->initiate($request->user(), $code, $validated['method'] ?? 'gcash', $validated['bank'] ?? null);
            $transaction = $result['transaction'];

            return response()->json([
                'success' => true,
                'data' => $this->present($transaction, includeLink: true),
            ], $result['created'] ? 201 : 200);
        });
    }

    public function show(Request $request, string $code): JsonResponse
    {
        return $this->respond(function () use ($request, $code) {
            $data = $this->payments->statusFor($request->user(), $code);

            // Once the paid booking is submitted, the app shows the same "Booking successful" summary as a Cash request.
            if ($data && ($data['booking_submitted'] ?? false) && ($data['pay_at_booking'] ?? false)) {
                $anchor = \App\Models\Booking::where('booking_code', $data['booking_code'])->first();
                $members = $anchor && $anchor->group_code
                    ? \App\Models\Booking::where('group_code', $anchor->group_code)->where('customer_id', $anchor->customer_id)->orderBy('id')->with(['truckType', 'vehicleType'])->get()
                    : collect($anchor ? [$anchor->load(['truckType', 'vehicleType'])] : []);
                $summaries = app(CustomerBookingController::class);
                $data['bookings'] = $members->map(fn ($b) => $summaries->bookingSummary($b, $anchor->booking_code))->values()->all();
            }

            return response()->json(['success' => true, 'data' => $data]);
        });
    }

    /**
     * Step 3: may this customer use Pay & Submit, and with which channel? Server-decided; Online Banking is off unless
     * configured. It is reported only as `banks`, never under `channels`: APK 1.0.13 reads `channels` and has no bank
     * picker, so it keeps showing Online Banking as not available.
     */
    public function availability(Request $request): JsonResponse
    {
        $available = $this->payments->canPayAtBooking($request->user());

        return response()->json([
            'success' => true,
            'data' => [
                'available' => $available,
                'channels' => [
                    'gcash' => $available && $this->payments->channelAvailable('gcash'),
                ],
                'banks' => $available
                    ? collect($this->payments->onlineBanks())->map(fn (array $bank, string $code) => ['code' => $code, 'label' => $bank['label']])->values()->all()
                    : [],
                'book_now_only' => true,
                'test_mode' => true,
            ],
        ]);
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
            'bank' => $transaction->bank(),
            'amount' => Money::centavosToPesos($transaction->amount_centavos),
            'currency' => $transaction->currency,
            'expires_at' => $transaction->expires_at?->toIso8601String(),
            'checkout_url' => $includeLink && $transaction->isOpen() ? $transaction->checkout_url : null,
        ];
    }
}
