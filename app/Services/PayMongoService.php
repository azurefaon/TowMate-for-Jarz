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
        $this->secretKey = (string) config('services.paymongo.secret_key', '');
    }

    protected function headers(): array
    {
        return [
            'Authorization' => 'Basic ' . base64_encode($this->secretKey . ':'),
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ];
    }

    public function createPaymentLink(int $amountCentavos, string $description): array
    {
        try {
            $response = Http::timeout(15)->withHeaders($this->headers())
                ->post("{$this->baseUrl}/links", [
                    'data' => [
                        'attributes' => [
                            'amount'      => $amountCentavos,
                            'description' => $description,
                            'currency'    => 'PHP',
                        ],
                    ],
                ]);
        } catch (\Throwable $exception) {
            Log::error('PayMongo createPaymentLink request failed', ['error' => $exception->getMessage()]);

            return [];
        }

        if (! $response->successful()) {
            Log::error('PayMongo createPaymentLink failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
        }

        return $response->json() ?? [];
    }

    public function createPaymentIntent(int $amountCentavos, string $description): array
    {
        try {
            $response = Http::timeout(15)->withHeaders($this->headers())
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
        } catch (\Throwable $exception) {
            Log::error('PayMongo createPaymentIntent request failed', ['error' => $exception->getMessage()]);

            return [];
        }

        if (! $response->successful()) {
            Log::error('PayMongo createPaymentIntent failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
        }

        return $response->json() ?? [];
    }

    public function getPaymentIntent(string $intentId, string $clientKey): array
    {
        try {
            $response = Http::timeout(15)->withHeaders($this->headers())
                ->get("{$this->baseUrl}/payment_intents/{$intentId}", [
                    'client_key' => $clientKey,
                ]);
        } catch (\Throwable $exception) {
            Log::error('PayMongo getPaymentIntent request failed', ['error' => $exception->getMessage()]);

            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        return $response->json('data.attributes') ?? [];
    }

    public function isIntentPaid(string $intentId, string $clientKey): bool
    {
        $attributes = $this->getPaymentIntent($intentId, $clientKey);

        return ($attributes['status'] ?? '') === 'succeeded';
    }

    public function getLink(string $linkId): array
    {
        try {
            $response = Http::timeout(15)->withHeaders($this->headers())
                ->get("{$this->baseUrl}/links/{$linkId}");
        } catch (\Throwable $exception) {
            Log::error('PayMongo getLink request failed', ['error' => $exception->getMessage()]);

            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        return $response->json('data.attributes') ?? [];
    }

    public function isLinkPaid(string $linkId): bool
    {
        $attributes = $this->getLink($linkId);

        return ($attributes['status'] ?? 'unpaid') === 'paid';
    }
}
