<?php

namespace App\Exceptions\Fx;

use RuntimeException;

/**
 * Thrown when the variance exceeds the absolute block threshold and no override is possible.
 */
class PaymentBlockedException extends RuntimeException {}
