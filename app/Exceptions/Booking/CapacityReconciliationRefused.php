<?php

namespace App\Exceptions\Booking;

use RuntimeException;

/**
 * The capacity reconciliation was refused before changing anything, or was
 * rolled back. The message is safe to show and log: it names booking codes and
 * dates only, never customer details.
 */
class CapacityReconciliationRefused extends RuntimeException
{
}
