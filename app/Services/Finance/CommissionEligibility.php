<?php

namespace App\Services\Finance;

/**
 * Display rule shared by Driver Records and Commission (Draft): which draft commission counts as eligible.
 *
 * Only a completed, fully paid job (authoritative JobCollectionService status 'paid') is eligible; its share comes
 * unchanged from CommissionCalculator. Other completed jobs stay visible with a label and their calculator share is
 * at most a non-payable estimate. This changes no calculation and no approval rule (approval still needs zero
 * blockers, and a job that is not fully paid always carries the payment_not_verified blocker).
 */
final class CommissionEligibility
{
    public const ELIGIBLE = 'eligible';
    public const PENDING_COLLECTION = 'pending_collection';
    public const AWAITING_PAYMENT = 'awaiting_payment';
    public const UNDER_REVIEW = 'under_review';

    /** Badge colour for each state (display only; see .fin-status in finance.css). */
    public const TONES = [
        self::ELIGIBLE => 'green',
        self::PENDING_COLLECTION => 'amber',
        self::AWAITING_PAYMENT => 'amber',
        self::UNDER_REVIEW => 'blue',
    ];

    public const LABELS = [
        self::PENDING_COLLECTION => 'Pending Collection',
        self::AWAITING_PAYMENT => 'Awaiting Payment',
        self::UNDER_REVIEW => 'Under review (over-collected)',
    ];

    public static function state(string $paymentStatus, bool $serviceCompleted = true): string
    {
        if (! $serviceCompleted) {
            return self::AWAITING_PAYMENT;   // not counted as completed work anywhere; never eligible
        }

        return match ($paymentStatus) {
            'paid' => self::ELIGIBLE,
            'partial' => self::PENDING_COLLECTION,
            'overpaid' => self::UNDER_REVIEW,
            default => self::AWAITING_PAYMENT,   // unpaid, awaiting_verification, completed_unverified
        };
    }

    /** For a calculator line (CommissionCalculator::line carries the job's payment status). */
    public static function forLine(array $line): string
    {
        return self::state((string) ($line['payment_status'] ?? ''), (bool) ($line['service_completed'] ?? false));
    }
}
