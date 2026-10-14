<?php

namespace App\Exceptions\Booking;

use RuntimeException;

/**
 * A booking says it holds a capacity slot on a date, but the counter for that
 * date can't give it back (no row, or already zero). The operation is refused
 * and logged instead of being hidden by clamping the counter.
 */
class CapacityInconsistentException extends RuntimeException
{
}
