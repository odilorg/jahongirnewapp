<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Thrown by Beds24BookingService::apiCall() when the global kill switch
 * BEDS24_INTEGRATION_ENABLED=false is active.
 *
 * Callers that catch this should NOT retry — the integration is
 * intentionally disabled. Log and return a controlled result.
 */
class Beds24IntegrationDisabledException extends \RuntimeException
{
    public function __construct(string $message = 'Beds24 integration is disabled.')
    {
        parent::__construct($message);
    }
}
