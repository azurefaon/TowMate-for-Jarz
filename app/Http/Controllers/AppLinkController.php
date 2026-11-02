<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AppLinkController extends Controller
{
    public const OUTCOMES = ['success', 'cancelled', 'failed'];

    /** Digital Asset Links: proves the TowMate Android app (by its signing certificate) may open our links. */
    public function assetLinks(): JsonResponse
    {
        return response()->json([[
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => [
                'namespace' => 'android_app',
                'package_name' => config('app_links.android_package'),
                'sha256_cert_fingerprints' => config('app_links.android_sha256_fingerprints'),
            ],
        ]], 200, ['Cache-Control' => 'public, max-age=3600'], JSON_UNESCAPED_SLASHES);
    }

    /**
     * Where PayMongo's hosted checkout sends the customer. On a phone with the verified App Link this page is never
     * shown (Android opens the app). When it IS shown, it only offers a way back: it never says "paid", because a
     * redirect proves nothing — the app asks the TowMate server for the verified status.
     */
    public function paymentReturn(Request $request, string $outcome): Response
    {
        abort_unless(in_array($outcome, self::OUTCOMES, true), 404);

        $booking = $this->bookingCode($request->query('booking'));
        $base = parse_url(config('app_links.base_url'));
        $path = config('app_links.payment_return_path') . '/' . $outcome . ($booking ? '?booking=' . rawurlencode($booking) : '');

        $intent = sprintf(
            'intent://%s%s#Intent;scheme=%s;package=%s;end',
            $base['host'] . (isset($base['port']) ? ':' . $base['port'] : ''),
            $path,
            $base['scheme'],
            config('app_links.android_package')
        );

        return response()
            ->view('public.payment-return', ['outcome' => $outcome, 'booking' => $booking, 'intent' => $intent])
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Robots-Tag', 'noindex');
    }

    /** Booking codes are short alphanumeric references; anything else is dropped rather than echoed. */
    protected function bookingCode(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9-]{1,40}$/', $value) ? $value : null;
    }
}
