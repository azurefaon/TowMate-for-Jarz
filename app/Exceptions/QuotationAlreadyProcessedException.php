<?php

namespace App\Exceptions;

use Exception;

/**
 * acceptQuotation() found the quotation already accepted, rejected or revised
 * once it had locked it, typically because the same request arrived twice.
 * Extends Exception, so callers that catch Exception behave as before.
 */
class QuotationAlreadyProcessedException extends Exception
{
}
