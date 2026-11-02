<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One online-payment attempt (a PayMongo Checkout Session) for a booking or a whole group.
 * Evidence only: it never changes a booking's status or any payment_method column.
 */
class PaymentTransaction extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';
    /** Money may have moved but it cannot be treated as verified (amount mismatch, unpayable booking). */
    public const STATUS_REVIEW = 'review';
    /** A second payment for something already paid. Needs a human refund decision; never auto-refunded. */
    public const STATUS_DUPLICATE = 'duplicate';

    protected $fillable = [
        'provider', 'reference', 'payable_type', 'payable_id', 'booking_id', 'customer_id',
        'initiated_by_user_id', 'method', 'amount_centavos', 'currency', 'payable_snapshot',
        'status', 'flag', 'provider_checkout_session_id', 'provider_payment_id', 'checkout_url',
        'livemode', 'last_event_id', 'failure_code', 'expires_at', 'paid_at',
    ];

    protected $hidden = ['checkout_url'];

    protected function casts(): array
    {
        return [
            'amount_centavos' => 'integer',
            'payable_snapshot' => 'array',
            'livemode' => 'boolean',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function amountPesos(): float
    {
        return $this->amount_centavos / 100;
    }

    private const FLAG_TEXT = [
        'cash_also_collected' => 'cash was also recorded - review for a refund',
        'amount_mismatch' => 'amount differs from the expected total',
        'superseded_total' => 'the booking total changed after checkout began',
        'paid_after_close' => 'paid after the checkout was closed',
        'booking_cancelled' => 'the booking was cancelled',
        'duplicate_payment' => 'a second payment for something already paid - review for a refund',
        'second_payment_same_session' => 'a second payment arrived on the same checkout',
    ];

    /**
     * One line for the dispatcher about online-payment evidence on this booking, or null. Evidence
     * only: the dispatcher still verifies and confirms payment as usual. Costs no query unless the
     * sandbox feature is on.
     */
    public static function dispatcherNote(Booking $booking): ?string
    {
        if (! config('paymongo.enabled')) {
            return null;
        }

        $transaction = static::query()
            ->whereIn('status', [self::STATUS_PAID, self::STATUS_REVIEW, self::STATUS_DUPLICATE])
            ->where(function ($query) use ($booking) {
                $query->where('booking_id', $booking->id);
                if ($booking->group_code) {
                    $query->orWhereIn('booking_id', Booking::where('group_code', $booking->group_code)->select('id'));
                }
            })
            ->latest('id')
            ->first();

        if (! $transaction) {
            return null;
        }

        $amount = 'P' . number_format($transaction->amount_centavos / 100, 2);
        $flag = $transaction->flag ? ' (' . (self::FLAG_TEXT[$transaction->flag] ?? $transaction->flag) . ')' : '';

        return match ($transaction->status) {
            self::STATUS_PAID => "Paid online (sandbox) {$amount}, ref {$transaction->reference}{$flag}",
            self::STATUS_DUPLICATE => "Duplicate online payment {$amount}, ref {$transaction->reference}{$flag}",
            default => "Online payment {$amount} needs review, ref {$transaction->reference}{$flag}",
        };
    }

    /** The verified-paid transaction covering this booking (as a single booking or as part of its group), if any. */
    public static function verifiedPaidFor(Booking $booking): ?self
    {
        return static::query()
            ->where('status', self::STATUS_PAID)
            ->where(function ($query) use ($booking) {
                $query->where('booking_id', $booking->id)
                    ->orWhere(function ($q) use ($booking) {
                        if ($booking->group_code) {
                            $q->whereIn('booking_id', Booking::where('group_code', $booking->group_code)->select('id'));
                        } else {
                            $q->whereRaw('1 = 0');
                        }
                    });
            })
            ->latest('id')
            ->first();
    }
}
