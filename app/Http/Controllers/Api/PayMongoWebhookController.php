<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OnlinePayments\OnlinePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives PayMongo SANDBOX events. The body is read raw and verified before anything is parsed;
 * the response never echoes the payload or the signature.
 */
class PayMongoWebhookController extends Controller
{
    public function __invoke(Request $request, OnlinePaymentService $payments): JsonResponse
    {
        $result = $payments->handleWebhook($request->getContent(), $request->header('Paymongo-Signature'));

        if ($result['status'] === 404) {
            abort(404);
        }

        return response()->json(
            ['received' => $result['status'] < 400, 'outcome' => $result['outcome']],
            $result['status'],
        );
    }
}
