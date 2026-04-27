<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PayMongoService
{
    protected string $baseUrl;
    protected string $secretKey;

    public function __construct()
    {
        $this->baseUrl   = config('services.paymongo.base_url', 'https://api.paymongo.com/v1');
        $this->secretKey = config('services.paymongo.secret_key', '');
    }

    protected function headers(): array
    {
        return [
            'Authorization' => 'Basic ' . base64_encode($this->secretKey . ':'),
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ];
    }

    /**
     * Create a PayMongo Payment Link.
     * Amount is in centavos (PHP 1.00 = 100 centavos).
     */
    public function createPaymentLink(int $amountCentavos, string $description): array
    {
        $response = Http::withHeaders($this->headers())
            ->post("{$this->baseUrl}/links", [
                'data' => [
                    'attributes' => [
                        'amount'      => $amountCentavos,
                        'description' => $description,
                        'currency'    => 'PHP',
                    ],
                ],
            ]);

        if (! $response->successful()) {
            Log::error('PayMongo createPaymentLink failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
        }

        return $response->json() ?? [];
    }

    /**
     * Create a PayMongo Payment Intent (for card payments).
     */
    public function createPaymentIntent(int $amountCentavos, string $description): array
    {
        $response = Http::withHeaders($this->headers())
            ->post("{$this->baseUrl}/payment_intents", [
                'data' => [
                    'attributes' => [
                        'amount'                 => $amountCentavos,
                        'payment_method_allowed' => ['card'],
                        'currency'               => 'PHP',
                        'description'            => $description,
                        'capture_type'           => 'automatic',
                    ],
                ],
            ]);

        if (! $response->successful()) {
            Log::error('PayMongo createPaymentIntent failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
        }

        return $response->json() ?? [];
    }

    /**
     * Retrieve a Payment Intent by ID.
     */
    public function getPaymentIntent(string $intentId, string $clientKey): array
    {
        $response = Http::withHeaders($this->headers())
            ->get("{$this->baseUrl}/payment_intents/{$intentId}", [
                'client_key' => $clientKey,
            ]);

        return $response->json('data.attributes') ?? [];
    }

    /**
     * Returns true when the payment intent status is "succeeded".
     */
    public function isIntentPaid(string $intentId, string $clientKey): bool
    {
        $attributes = $this->getPaymentIntent($intentId, $clientKey);

        return ($attributes['status'] ?? '') === 'succeeded';
    }

    /**
     * Get the current state of a payment link.
     * Returns the link attributes array or empty array on failure.
     */
    public function getLink(string $linkId): array
    {
        $response = Http::withHeaders($this->headers())
            ->get("{$this->baseUrl}/links/{$linkId}");

        return $response->json('data.attributes') ?? [];
    }

    /**
     * Returns true when the payment link status is "paid".
     */
    public function isLinkPaid(string $linkId): bool
    {
        $attributes = $this->getLink($linkId);

        return ($attributes['status'] ?? 'unpaid') === 'paid';
    }
}
