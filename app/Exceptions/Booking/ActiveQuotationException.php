<?php

namespace App\Exceptions\Booking;

class ActiveQuotationException extends BookingException
{
    public function __construct(public readonly string $quotationNumber, string $timeMessage)
    {
        parent::__construct(
            "You have a pending quotation (Ref: {$quotationNumber}). " .
            "Please accept, reject, or wait for it to expire ({$timeMessage}) before requesting a new quotation."
        );
    }
}
