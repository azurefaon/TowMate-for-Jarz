<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Quotation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Public (unauthenticated) booking tracker. Booking codes are sequential, so
 * a reference alone must never reveal anything: the customer also proves the
 * last 4 digits of the phone on the booking. Every verification failure —
 * unknown reference, wrong digits, a phone-less customer, an expired booking —
 * returns the identical generic response so the page can't be used to
 * enumerate bookings. Submitted digits are never stored, flashed or logged
 * (the form POSTs, so they stay out of URLs and access logs too).
 */
class PublicTrackController extends Controller
{
    public const GENERIC_FAILURE = "We couldn't verify that booking. Please check the booking reference and phone number.";

    public const TOO_MANY_ATTEMPTS = 'Too many attempts for this reference. Please try again later.';

    /** Failed verifications allowed per reference before it is locked out. */
    public const MAX_FAILURES_PER_REFERENCE = 10;

    public const REFERENCE_LOCKOUT_SECONDS = 3600;

    /** How long a completed booking stays publicly trackable. */
    public const COMPLETED_GRACE_HOURS = 24;

    /** Terminal outcomes that are never publicly trackable. */
    protected const HIDDEN_STATUSES = ['cancelled', 'rejected', 'not_responding'];

    public function index(Request $request)
    {
        // ?ref= only pre-fills the form (e.g. the link on the quotation page);
        // it never triggers a lookup.
        return $this->render(['ref' => $this->cleanReference($request->query('ref'))]);
    }

    public function verify(Request $request)
    {
        $ref = $this->cleanReference($request->input('ref'));
        $digits = trim((string) $request->input('phone_last4', ''));

        if ($ref === '') {
            return $this->render(['ref' => $ref, 'error' => 'Enter your booking reference.'], 422);
        }

        if (! preg_match('/^\d{4}$/', $digits)) {
            return $this->render(['ref' => $ref, 'error' => 'Enter exactly 4 digits from your phone number.'], 422);
        }

        // Keyed on the normalized reference whether or not it exists, so the
        // lockout itself reveals nothing; caps brute force of the 4-digit
        // verifier per booking regardless of how many IPs are used.
        $limiterKey = 'public-track:ref:' . sha1(strtolower($ref));

        if (RateLimiter::tooManyAttempts($limiterKey, self::MAX_FAILURES_PER_REFERENCE)) {
            return $this->render(['ref' => $ref, 'error' => self::TOO_MANY_ATTEMPTS], 429);
        }

        // Both lookups always run so an unknown reference costs the same
        // queries as a known one. findBooking() ignores status on purpose: a
        // quotation is only tracked on its own until a booking (even a
        // hidden/expired one) carries the reference — then the booking's
        // visibility rule wins.
        $booking = $this->findBooking($ref);
        $quotation = $this->findQuotation($ref);

        $subject = $booking ?? $quotation;
        $phoneMatches = $this->phoneMatches($subject?->customer?->phone, $digits);

        if (! $subject || ! $phoneMatches || ($booking && ! $this->isPubliclyVisible($booking))) {
            RateLimiter::hit($limiterKey, self::REFERENCE_LOCKOUT_SECONDS);

            return $this->render(['ref' => $ref, 'error' => self::GENERIC_FAILURE], 422);
        }

        if ($booking) {
            $groupBookings = $booking->group_code
                ? Booking::with('truckType')
                    ->where('group_code', $booking->group_code)
                    ->where('customer_id', $booking->customer_id)
                    ->where('id', '!=', $booking->id)
                    ->whereNotIn('status', self::HIDDEN_STATUSES)
                    ->orderBy('id')
                    ->get()
                : collect();

            return $this->render(['ref' => $ref, 'booking' => $booking, 'groupBookings' => $groupBookings]);
        }

        return $this->render(['ref' => $ref, 'quotation' => $quotation]);
    }

    protected function render(array $data, int $status = 200)
    {
        return response()->view('public.track', array_merge([
            'ref' => '',
            'error' => null,
            'booking' => null,
            'quotation' => null,
            'groupBookings' => collect(),
        ], $data), $status);
    }

    protected function cleanReference(mixed $value): string
    {
        $ref = trim(is_string($value) ? $value : '');

        // Booking codes, group codes and quotation numbers are all short
        // alphanumeric/hyphen strings; anything else can't match, so drop it
        // rather than echo it back.
        return preg_match('/^[A-Za-z0-9-]{1,40}$/', $ref) ? $ref : '';
    }

    protected function findBooking(string $ref): ?Booking
    {
        return Booking::with(['customer', 'truckType'])
            ->where(function ($q) use ($ref) {
                $q->where('booking_code', $ref)
                    ->orWhere('quotation_number', $ref)
                    ->orWhere('group_code', $ref);
            })
            ->orderBy('id')
            ->first();
    }

    protected function findQuotation(string $ref): ?Quotation
    {
        return Quotation::with(['customer', 'truckType'])
            ->where('quotation_number', $ref)
            ->current()
            ->whereNotIn('status', ['rejected'])
            ->first();
    }

    protected function phoneMatches(?string $storedPhone, string $digits): bool
    {
        $storedDigits = preg_replace('/\D+/', '', (string) $storedPhone);

        // Constant-time compare against a placeholder when there is no usable
        // phone, so a phone-less customer looks exactly like a wrong guess.
        $expected = strlen($storedDigits) >= 4 ? substr($storedDigits, -4) : '----';

        return hash_equals($expected, $digits);
    }

    /**
     * Active bookings are trackable; completed ones only for a short grace
     * period after completion; cancelled/rejected/not-responding never.
     */
    protected function isPubliclyVisible(Booking $booking): bool
    {
        if (in_array($booking->status, self::HIDDEN_STATUSES, true)) {
            return false;
        }

        if ($booking->status === 'completed') {
            $completedAt = $booking->completed_at ?? $booking->updated_at;

            return $completedAt !== null
                && $completedAt->gte(now()->subHours(self::COMPLETED_GRACE_HOURS));
        }

        return true;
    }
}
