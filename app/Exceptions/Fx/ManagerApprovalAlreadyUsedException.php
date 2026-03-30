<?php

namespace App\Exceptions\Fx;

use RuntimeException;

/**
 * Thrown when an approval row is already in "consumed" state (single-use invariant).
 */
class ManagerApprovalAlreadyUsedException extends RuntimeException {}
