<?php

namespace App\Exceptions\Fx;

use RuntimeException;

/**
 * Thrown when a required manager approval row cannot be found or has already expired.
 */
class ManagerApprovalNotFoundException extends RuntimeException {}
