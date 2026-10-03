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

if (!function_exists('protected_file_url')) {
    function protected_file_url(?string $path, int $expiresInMinutes = 30): ?string
    {
        if (!filled($path)) {
            return null;
        }

        $path = ltrim($path, '/');

        if (\Illuminate\Support\Facades\Storage::disk('local')->exists($path)) {
            return \Illuminate\Support\Facades\Storage::disk('local')->temporaryUrl(
                $path,
                now()->addMinutes($expiresInMinutes)
            );
        }

        return \Illuminate\Support\Facades\Storage::disk('public')->url($path);
    }
}

if (!function_exists('mobile_content_url')) {
    function mobile_content_url(?string $path): ?string
    {
        if (!filled($path)) {
            return null;
        }

        return url('/api/media/mobile/'.basename($path));
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

if (!function_exists('redact_ip_addresses')) {
    /**
     * Display-only: strips IPv4/IPv6 addresses (and phrases like "from IP x")
     * from free text shown to System Admins. Never used when storing data.
     */
    function redact_ip_addresses(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        $hex = '[0-9a-f]{1,4}';
        $ipv4 = '(?<![\d.])(?:\d{1,3}\.){3}\d{1,3}(?![\d.]*\d)';
        $ipv6 = "(?<![\w:])(?:(?:{$hex}:){7}{$hex}|(?:{$hex}:){1,7}:(?:{$hex}(?::{$hex}){0,6})?|::(?:{$hex}(?::{$hex}){0,6})?)(?![\w:])";
        $ip = "(?:{$ipv4}|{$ipv6})";

        $clean = preg_replace(
            "/[\s,;(]*\b(?:(?:from|via|at)\s+)?(?:(?:client|source|remote|request)\s+)?IP(?:\s+address)?\b\s*[:=]?\s*{$ip}\)?/i",
            '',
            $text
        ) ?? $text;

        $clean = preg_replace("/{$ip}/i", '', $clean) ?? $clean;
        $clean = trim(preg_replace(['/[ \t]{2,}/', '/\s+([.,;])/'], [' ', '$1'], $clean) ?? $clean);

        if ($clean !== trim($text) && $clean !== '' && ! preg_match('/[.!?]$/', $clean)) {
            $clean .= '.';
        }

        return $clean;
    }
}
