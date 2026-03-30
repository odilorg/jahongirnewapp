<?php

namespace App\Exceptions\Fx;

use RuntimeException;

/**
 * Thrown when the cashier tries to confirm a payment after the presentation TTL has expired.
 * The bot must restart the flow so rates are refreshed.
 */
class StalePaymentSessionException extends RuntimeException {}
