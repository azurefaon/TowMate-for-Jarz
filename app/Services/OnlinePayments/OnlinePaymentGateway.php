<?php

namespace App\Services\OnlinePayments;

/** The provider operations the sandbox needs. Bound to PayMongo; tests may swap or Http::fake it. */
interface OnlinePaymentGateway
{
    /**
     * @param  array{amount_centavos: int, name: string, description: string, reference: string, payment_method_types: array<int,string>, metadata: array<string,string>, success_url?: ?string, cancel_url?: ?string}  $params
     * @return array{id: string, checkout_url: string, livemode: bool}
     *
     * @throws GatewayException
     */
    public function createCheckoutSession(array $params): array;

    /**
     * @return array{status: string, livemode: bool, reference: ?string, payments: array<int, array{id: string, status: string, amount: int, currency: string}>}
     *
     * @throws GatewayException
     */
    public function retrieveCheckoutSession(string $sessionId): array;

    /** @throws GatewayException  (httpStatus 400 = already expired, paid or being paid) */
    public function expireCheckoutSession(string $sessionId): void;

    /**
     * Opens a bank payment: creates the Payment Intent and Payment Method and attaches them.
     *
     * @param  array{amount_centavos: int, description: string, type: string, bank_code: string, metadata: array<string,string>, return_url: string}  $params
     * @return array{id: string, checkout_url: string, livemode: bool}
     *
     * @throws GatewayException
     */
    public function createBankPayment(array $params): array;

    /**
     * @return array{status: string, livemode: bool, reference: ?string, payments: array<int, array{id: string, status: string, amount: int, currency: string}>, failure: ?string}
     *
     * @throws GatewayException
     */
    public function retrieveBankPayment(string $intentId): array;

    /** @throws GatewayException  (a 4xx = already finished, so it can no longer be cancelled) */
    public function cancelBankPayment(string $intentId): void;
}
