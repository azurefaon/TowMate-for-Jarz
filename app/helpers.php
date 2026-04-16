<?php

if (!function_exists('setting')) {
    function setting($key, $default = null)
    {
        $settings = config('towmate.settings');

        if (is_array($settings) && array_key_exists($key, $settings)) {
            return $settings[$key];
        }

        return $default;
    }
}

if (!function_exists('split_full_name')) {
    function split_full_name(?string $name): array
    {
        $cleaned = preg_replace('/\s+/', ' ', trim((string) $name));

        if ($cleaned === '') {
            return [
                'first_name' => null,
                'middle_name' => null,
                'last_name' => null,
            ];
        }

        $parts = explode(' ', $cleaned);
        $firstName = array_shift($parts);
        $lastName = count($parts) > 0 ? array_pop($parts) : null;
        $middleName = count($parts) > 0 ? implode(' ', $parts) : null;

        return [
            'first_name' => $firstName ?: null,
            'middle_name' => $middleName ?: null,
            'last_name' => $lastName ?: null,
        ];
    }
}

if (!function_exists('build_full_name')) {
    function build_full_name(?string $firstName, ?string $middleName = null, ?string $lastName = null): string
    {
        return trim(implode(' ', array_filter([
            trim((string) $firstName),
            trim((string) $middleName),
            trim((string) $lastName),
        ], fn($value) => $value !== '')));
    }
}

if (!function_exists('normalize_ph_phone')) {
    function normalize_ph_phone(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '63') && strlen($digits) === 12) {
            return '+' . $digits;
        }

        if (str_starts_with($digits, '09') && strlen($digits) === 11) {
            return '+63' . substr($digits, 1);
        }

        if (str_starts_with($digits, '9') && strlen($digits) === 10) {
            return '+639' . substr($digits, 1);
        }

        return null;
    }
}

if (!function_exists('public_email_domains')) {
    function public_email_domains(): array
    {
        return [
            'gmail.com',
            'yahoo.com',
            'ymail.com',
            'outlook.com',
            'hotmail.com',
            'live.com',
            'icloud.com',
            'aol.com',
            'gmx.com',
            'proton.me',
            'protonmail.com',
            'example.com',
        ];
    }
}

if (!function_exists('is_public_email')) {
    function is_public_email(?string $email): bool
    {
        $normalized = strtolower(trim((string) $email));

        if ($normalized === '' || ! filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $domain = substr(strrchr($normalized, '@') ?: '', 1);

        return in_array($domain, public_email_domains(), true);
    }
}
