<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\TourProductDirection;
use App\Services\AutoExportScheduler;

class TourProductDirectionObserver
{
    public function __construct(private AutoExportScheduler $scheduler)
    {
    }

    public function saved(TourProductDirection $direction): void
    {
        $this->scheduler->schedule();
    }

    public function deleted(TourProductDirection $direction): void
    {
        $this->scheduler->schedule();
    }
}
