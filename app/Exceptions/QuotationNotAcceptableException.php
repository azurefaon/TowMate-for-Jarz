<?php

namespace App\Exceptions;

/**
 * acceptQuotation() refused a quotation that is still marked "sent" but cannot
 * be accepted any more: it has expired, or the scheduled date/time it would
 * confirm has already passed. The message is written for the customer.
 *
 * Extends QuotationAlreadyProcessedException so callers that already handle
 * that exception keep working unchanged; callers that want to show the
 * specific reason catch this class first.
 */
class QuotationNotAcceptableException extends QuotationAlreadyProcessedException
{
}
