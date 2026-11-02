<?php

namespace App\Services\OnlinePayments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * PayMongo Hosted Checkout (v2) over HTTP Basic with the TEST secret key.
 *
 * Contract, from PayMongo's own pages (docs.paymongo.com):
 *   POST /v2/checkout_sessions            body {data:{attributes:{line_items, payment_method_types,
 *                                         reference_number, metadata, description, success_url,
 *                                         cancel_url, send_email_receipt}}}; amounts in centavos, PHP only.
 *   GET  /v1/checkout_sessions/{id}       described as checking the v2 store first and falling back to
 *                                         legacy sessions (so it should serve v2 sessions — unverified).
 *   POST /v1/checkout_sessions/{id}/expire  the page never mentions v2 — unverified for v2 sessions.
 *
 * The create response's nesting is shown two ways in PayMongo's docs (`data.attributes.checkout_url`
 * in the API reference, flatter in the quick start), so both are read; anything that cannot be
 * confirmed as a TEST-mode session with a PayMongo https link is rejected rather than trusted.
 * Neither the key nor raw provider bodies are ever logged or placed in exception messages.
 */
class PayMongoCheckoutGateway implements OnlinePaymentGateway
{
    public function __construct(protected SandboxGuard $guard)
    {
    }

    protected function http(): PendingRequest
    {
        return Http::withBasicAuth($this->guard->secretKey(), '')
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('paymongo.http_timeout_seconds', 15));
    }

    protected function url(string $path): string
    {
        return config('paymongo.base_url') . $path;
    }

    public function createCheckoutSession(array $params): array
    {
        $attributes = array_filter([
            'line_items' => [[
                'name' => $params['name'],
                'description' => $params['description'],
                'amount' => $params['amount_centavos'], // centavos: 1.00 PHP = 100
                'currency' => 'PHP',
                'quantity' => 1,
            ]],
            'payment_method_types' => $params['payment_method_types'],
            'reference_number' => $params['reference'],
            'description' => $params['description'],
            'metadata' => $params['metadata'],
            'success_url' => $params['success_url'] ?? null,
            'cancel_url' => $params['cancel_url'] ?? null,
            'send_email_receipt' => false,
        ], fn ($value) => $value !== null);

        $response = $this->send(fn () => $this->http()->post($this->url('/v2/checkout_sessions'), [
            'data' => ['attributes' => $attributes],
        ]));

        $data = $response->json('data');

        if (! is_array($data)) {
            throw $this->unexpected($response);
        }

        // Documented under `attributes`; the quick start shows the fields without that level.
        $fields = is_array($data['attributes'] ?? null) ? $data['attributes'] : $data;
        $id = $data['id'] ?? null;
        $checkoutUrl = $fields['checkout_url'] ?? null;
        $livemode = $fields['livemode'] ?? ($data['livemode'] ?? null);

        if (! is_string($id) || ! preg_match('/^cs_[A-Za-z0-9_]+$/', $id)
            || ! is_string($checkoutUrl) || ! $this->isTrustedCheckoutUrl($checkoutUrl)
            || ! is_bool($livemode)) {
            throw $this->unexpected($response);
        }

        return ['id' => $id, 'checkout_url' => $checkoutUrl, 'livemode' => $livemode];
    }

    public function retrieveCheckoutSession(string $sessionId): array
    {
        $response = $this->send(fn () => $this->http()->get($this->url('/v1/checkout_sessions/' . rawurlencode($sessionId))));

        $attributes = $response->json('data.attributes');

        if (! is_array($attributes)) {
            throw $this->unexpected($response);
        }

        $payments = [];
        foreach ((array) ($attributes['payments'] ?? []) as $payment) {
            $payments[] = [
                'id' => (string) ($payment['id'] ?? ''),
                'status' => (string) ($payment['attributes']['status'] ?? ''),
                'amount' => is_int($payment['attributes']['amount'] ?? null) ? $payment['attributes']['amount'] : 0,
                'currency' => (string) ($payment['attributes']['currency'] ?? 'PHP'),
            ];
        }

        return [
            'status' => (string) ($attributes['status'] ?? ''),
            'livemode' => (bool) ($attributes['livemode'] ?? false),
            'reference' => is_string($attributes['reference_number'] ?? null) ? $attributes['reference_number'] : null,
            'payments' => $payments,
        ];
    }

    public function expireCheckoutSession(string $sessionId): void
    {
        $this->send(fn () => $this->http()->post($this->url('/v1/checkout_sessions/' . rawurlencode($sessionId) . '/expire')));
    }

    /** Only an https link on a PayMongo host (config paymongo.checkout_url_hosts) is ever handed to a customer. */
    protected function isTrustedCheckoutUrl(string $url): bool
    {
        if (strlen($url) > 2048) {
            return false;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));

        foreach ((array) config('paymongo.checkout_url_hosts') as $allowed) {
            $allowed = strtolower(trim((string) $allowed));

            if ($allowed !== '' && ($host === $allowed || str_ends_with($host, '.' . $allowed))) {
                return true;
            }
        }

        return false;
    }

    protected function unexpected(Response $response): GatewayException
    {
        return new GatewayException('The payment provider returned an unexpected response.', $response->status(), 'unexpected_response', $this->diagnose($response));
    }

    /**
     * An operator-only description of a response: its STRUCTURE (key paths and value types, never values, apart
     * from a link's host and a few enumerated words) and the provider's error code/detail, with anything shaped
     * like a credential redacted. Contains nothing that could be a secret.
     */
    protected function diagnose(Response $response): string
    {
        $json = $response->json();
        $parts = ['http ' . $response->status()];

        if (! is_array($json)) {
            return implode(' | ', $parts + [1 => 'body was not a JSON object']);
        }

        $parts[] = 'structure: ' . implode('; ', array_slice($this->shape($json), 0, 40));

        foreach (array_slice((array) ($json['errors'] ?? []), 0, 3) as $error) {
            if (is_array($error)) {
                $parts[] = 'error code=' . $this->safe($error['code'] ?? '') . ' detail=' . $this->safe($error['detail'] ?? '');
            }
        }

        return implode(' | ', $parts);
    }

    /** @return array<int, string> */
    private function shape(array $data, string $path = '', int $depth = 0): array
    {
        if ($depth > 5) {
            return [];
        }

        $lines = [];

        foreach ($data as $key => $value) {
            $here = $path === '' ? (string) $key : $path . '.' . $key;

            if (is_array($value)) {
                $isList = array_is_list($value);
                $lines[] = $here . ': ' . ($isList ? 'list(' . count($value) . ')' : 'object');

                if (! $isList) {
                    $lines = array_merge($lines, $this->shape($value, $here, $depth + 1));
                } elseif (isset($value[0]) && is_array($value[0])) {
                    // Describe a list by its first element only (its fields, as <path>[0].<field>).
                    $lines = array_merge($lines, $this->shape($value[0], $here . '[0]', $depth + 1));
                }

                continue;
            }

            $lines[] = $here . ': ' . $this->describeScalar((string) $key, $value);
        }

        return $lines;
    }

    private function describeScalar(string $key, mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'bool=' . ($value ? 'true' : 'false'),
            is_int($value) || is_float($value) => 'number',
            $value === null => 'null',
            ! is_string($value) => 'other',
            $key === 'checkout_url' => 'url host=' . $this->safe((string) parse_url($value, PHP_URL_HOST)),
            $key === 'id' => 'string(len ' . strlen($value) . ', prefix ' . $this->safe(substr($value, 0, 3)) . ')',
            in_array($key, ['type', 'status', 'currency'], true) && preg_match('/^[a-z_.]{1,40}$/', $value) => 'string=' . $value,
            default => 'string(len ' . strlen($value) . ')',
        };
    }

    /** Printable, short, and with anything key-shaped removed. */
    private function safe(mixed $value): string
    {
        $text = is_scalar($value) ? (string) $value : '';
        $text = preg_replace('/\b(?:sk|pk|whsk|whsec)_[A-Za-z0-9_]+/i', '[redacted]', $text) ?? '';
        $text = preg_replace('/[^\x20-\x7E]/', '', $text) ?? '';

        return substr($text, 0, 200);
    }

    /** @param  callable(): Response  $call */
    protected function send(callable $call): Response
    {
        try {
            $response = $call();
        } catch (ConnectionException) {
            throw new GatewayException('The payment provider could not be reached.', null, 'connection_failed');
        }

        if ($response->failed()) {
            $code = $response->json('errors.0.code');

            throw new GatewayException(
                'The payment provider refused the request.',
                $response->status(),
                is_string($code) ? $code : null,
                $this->diagnose($response),
            );
        }

        return $response;
    }
}
