<?php

namespace App\Services\Auth;

use App\Contracts\GoogleIdTokenVerifier;
use Google\Client;

class GoogleApiClientIdTokenVerifier implements GoogleIdTokenVerifier
{
    public function verify(string $idToken): ?array
    {
        if (trim($idToken) === '') {
            return null;
        }

        $clientId = (string) config('services.google.client_id');

        if ($clientId === '') {
            return null;
        }

        try {
            $client = new Client(['client_id' => $clientId]);
            $payload = $client->verifyIdToken($idToken);
        } catch (\Throwable $e) {
            return null;
        }

        if (!is_array($payload) || empty($payload['sub']) || empty($payload['email'])) {
            return null;
        }

        if (($payload['email_verified'] ?? false) !== true && ($payload['email_verified'] ?? 'false') !== 'true') {
            return null;
        }

        return $payload;
    }
}
