<?php

namespace App\Exceptions;

/**
 * Thrown when a cashier bot payment is attempted for a booking that already
 * has a recorded CashTransaction from the cashier_bot source trigger.
 *
 * Covers standalone bookings. For grouped bookings the more specific
 * DuplicateGroupPaymentException is thrown when a sibling of an already-paid
 * group is submitted.
 */
class DuplicatePaymentException extends \RuntimeException {}
