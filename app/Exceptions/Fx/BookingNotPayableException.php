<?php

namespace App\Exceptions\Fx;

use RuntimeException;

/**
 * Thrown when a booking cannot accept a payment (e.g. already fully paid, cancelled).
 */
class BookingNotPayableException extends RuntimeException {}
