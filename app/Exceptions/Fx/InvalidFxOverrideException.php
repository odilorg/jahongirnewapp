<?php

declare(strict_types=1);

namespace App\Exceptions\Fx;

/**
 * Raised by the simplified FX threshold guard when an FX override
 * fails one of the two rules in config('cashier.fx.*'):
 *
 *   - 3% < |deviation_pct| ≤ 15% with no override_reason → required
 *   - |deviation_pct| > 15%                              → hard block
 *
 * Caught at the cashier-bot form layer so the operator sees a clear
 * Russian message instead of a 500. Replaces ManagerApprovalRequired-
 * Exception in the new flow.
 */
final class InvalidFxOverrideException extends \DomainException
{
}
