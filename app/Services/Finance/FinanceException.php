<?php

namespace App\Services\Finance;

use DomainException;

/** A finance business rule was not met (wrong status, mismatched amount, duplicate with different data). */
class FinanceException extends DomainException
{
}
