<?php

namespace App\Exceptions;

/**
 * Thrown by BotPaymentService::recordPayment() when a completed group payment
 * already exists for the same master_booking_id.
 *
 * This guards against a cashier entering a sibling booking ID after the
 * group was already charged via the master (or any other sibling).
 */
class DuplicateGroupPaymentException extends \RuntimeException {}
