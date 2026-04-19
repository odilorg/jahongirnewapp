<?php

declare(strict_types=1);

namespace App\Exceptions\Leads;

use Illuminate\Support\Collection;
use RuntimeException;

class AmbiguousLeadMatchException extends RuntimeException
{
    /**
     * @param  Collection<int, \App\Models\Lead>  $matches  The conflicting leads — carried on the exception so the UI can render names, not just IDs.
     */
    public function __construct(
        string $message,
        public readonly Collection $matches,
    ) {
        parent::__construct($message);
    }
}
