<?php

namespace App\Services\Push;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Minimal FCM HTTP v1 client (service-account OAuth, no legacy server key).
 *
 * Credentials come from server-side config only:
 *   FCM_SERVICE_ACCOUNT_JSON_BASE64  base64 of the service-account JSON (secret)
 *   FCM_SERVICE_ACCOUNT_PATH         alternative: absolute path to the JSON file (local dev)
 *   FCM_PROJECT_ID                   optional; defaults to the JSON's project_id
 */
class FcmClient
{
    public const RESULT_SENT = 'sent';
    public const RESULT_INVALID_TOKEN = 'invalid_token';
    public const RESULT_FAILED = 'failed';

    public const CHANNEL_ID = 'towmate_booking_updates';

    private const TOKEN_CACHE_KEY = 'fcm.oauth_access_token';

    public function isConfigured(): bool
    {
        return $this->credentials() !== null;
    }

    /** @param array<string,string> $data */
    public function send(string $deviceToken, string $title, string $body, array $data): string
    {
        $credentials = $this->credentials();
        if ($credentials === null) {
            return self::RESULT_FAILED;
        }

        try {
            $accessToken = $this->accessToken($credentials);
            if ($accessToken === null) {
                return self::RESULT_FAILED;
            }

            $projectId = config('services.fcm.project_id') ?: ($credentials['project_id'] ?? null);
            if (! $projectId) {
                return self::RESULT_FAILED;
            }

            $response = Http::withToken($accessToken)
                ->connectTimeout(3)
                ->timeout(4)
                ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                    'message' => [
                        'token'        => $deviceToken,
                        'notification' => ['title' => $title, 'body' => $body],
                        'data'         => array_map('strval', $data),
                        'android'      => [
                            'priority'     => 'HIGH',
                            'notification' => ['channel_id' => self::CHANNEL_ID],
                        ],
                    ],
                ]);

            if ($response->successful()) {
                return self::RESULT_SENT;
            }

            if ($response->status() === 401) {
                Cache::forget(self::TOKEN_CACHE_KEY);
            }

            return $this->isStaleTokenResponse($response->status(), $response->json())
                ? self::RESULT_INVALID_TOKEN
                : self::RESULT_FAILED;
        } catch (\Throwable $e) {
            Log::warning('FCM send failed', ['error' => class_basename($e)]);

            return self::RESULT_FAILED;
        }
    }

    /** @param array<string,mixed>|null $json */
    private function isStaleTokenResponse(int $status, ?array $json): bool
    {
        $error = $json['error'] ?? [];
        $codes = collect($error['details'] ?? [])->pluck('errorCode')->filter()->all();

        if (in_array('UNREGISTERED', $codes, true) || $status === 404) {
            return true;
        }

        return in_array('INVALID_ARGUMENT', $codes, true)
            && stripos((string) ($error['message'] ?? ''), 'token') !== false;
    }

    /** @return array{client_email:string, private_key:string, project_id?:string}|null */
    private function credentials(): ?array
    {
        $raw = null;
        if ($b64 = config('services.fcm.service_account_base64')) {
            $raw = base64_decode((string) $b64, true) ?: null;
        } elseif (($path = config('services.fcm.service_account_path')) && is_file($path)) {
            $raw = file_get_contents($path) ?: null;
        }

        $decoded = $raw ? json_decode($raw, true) : null;

        if (! is_array($decoded) || empty($decoded['client_email']) || empty($decoded['private_key'])) {
            return null;
        }

        return $decoded;
    }

    private function accessToken(array $credentials): ?string
    {
        if ($cached = Cache::get(self::TOKEN_CACHE_KEY)) {
            return $cached;
        }

        $now = time();
        $segments = [
            $this->b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            $this->b64url(json_encode([
                'iss'   => $credentials['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud'   => 'https://oauth2.googleapis.com/token',
                'iat'   => $now,
                'exp'   => $now + 3600,
            ])),
        ];

        $signature = '';
        if (! openssl_sign(implode('.', $segments), $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
            return null;
        }
        $segments[] = $this->b64url($signature);

        $response = Http::asForm()
            ->connectTimeout(3)
            ->timeout(4)
            ->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => implode('.', $segments),
            ]);

        $token = $response->successful() ? $response->json('access_token') : null;
        if (! $token) {
            return null;
        }

        Cache::put(self::TOKEN_CACHE_KEY, $token, max(60, (int) $response->json('expires_in', 3600) - 300));

        return $token;
    }

    private function b64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
