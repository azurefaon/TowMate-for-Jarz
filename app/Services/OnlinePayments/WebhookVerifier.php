<?php

namespace App\Services\OnlinePayments;

/**
 * Verifies the `Paymongo-Signature` header against the RAW request body, exactly as PayMongo
 * documents it (docs.paymongo.com/docs/developer-tools-webhook-setup-management):
 *
 *   Paymongo-Signature: t=<timestamp>,te=<test signature>,li=<live signature>
 *   signed message    = <timestamp> . "." . <raw request body>
 *   signature         = HMAC-SHA256(signed message, webhook secret key), hex
 *
 * This is the TEST sandbox, so only `te` counts. A header with no `te` (live-only), a missing or
 * malformed header, a wrong signature, or a timestamp outside the tolerance is refused. There is
 * deliberately no fallback to any other scheme.
 */
class WebhookVerifier
{
    private const DEFAULT_TOLERANCE_SECONDS = 300;

    private const MAX_HEADER_LENGTH = 512;

    /** @return array{ok: bool, reason: string} */
    public function verify(string $rawBody, ?string $header, ?int $now = null): array
    {
        $secret = (string) config('paymongo.webhook_secret');

        if ($secret === '') {
            return ['ok' => false, 'reason' => 'missing_webhook_secret'];
        }

        $header = trim((string) $header);

        if ($header === '') {
            return ['ok' => false, 'reason' => 'missing_signature'];
        }

        $parts = $this->parse($header);

        if ($parts === null) {
            return ['ok' => false, 'reason' => 'malformed_signature'];
        }

        $timestamp = $parts['t'] ?? '';

        if ($timestamp === '' || ! ctype_digit($timestamp) || strlen($timestamp) > 12) {
            return ['ok' => false, 'reason' => 'malformed_signature'];
        }

        // A signature that is old (or from the future) may be a replay. The window is never switched off.
        $tolerance = (int) config('paymongo.webhook_tolerance_seconds');
        $tolerance = $tolerance > 0 ? $tolerance : self::DEFAULT_TOLERANCE_SECONDS;

        if (abs(($now ?? time()) - (int) $timestamp) > $tolerance) {
            return ['ok' => false, 'reason' => 'stale_timestamp'];
        }

        // Test mode compares `te` only. An empty `te` means a live-only signature, which this sandbox refuses.
        $candidate = $parts['te'] ?? '';

        if ($candidate === '') {
            return ['ok' => false, 'reason' => 'no_test_signature'];
        }

        if (! preg_match('/^[0-9a-fA-F]{64}$/', $candidate)) {
            return ['ok' => false, 'reason' => 'malformed_signature'];
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);

        return hash_equals($expected, strtolower($candidate))
            ? ['ok' => true, 'reason' => 'ok']
            : ['ok' => false, 'reason' => 'bad_signature'];
    }

    /**
     * Strictly `t=…,te=…,li=…` pairs: only those three names, none repeated.
     *
     * @return array<string, string>|null  null when the header is not in that shape
     */
    private function parse(string $header): ?array
    {
        if (strlen($header) > self::MAX_HEADER_LENGTH) {
            return null;
        }

        $parts = [];

        foreach (explode(',', $header) as $piece) {
            if (! preg_match('/^\s*(t|te|li)=([^,\s]*)\s*$/', $piece, $match)) {
                return null;
            }

            if (array_key_exists($match[1], $parts)) {
                return null;
            }

            $parts[$match[1]] = $match[2];
        }

        return $parts;
    }
}
