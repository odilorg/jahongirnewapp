<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\TourPriceTier;
use App\Services\AutoExportScheduler;

class TourPriceTierObserver
{
    public function __construct(private AutoExportScheduler $scheduler)
    {
    }

    public function saved(TourPriceTier $tier): void
    {
        $this->scheduler->schedule();
    }

    public function deleted(TourPriceTier $tier): void
    {
        $this->scheduler->schedule();
    }
}
