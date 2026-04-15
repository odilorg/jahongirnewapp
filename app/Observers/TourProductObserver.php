<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\TourProduct;
use App\Services\AutoExportScheduler;

class TourProductObserver
{
    public function __construct(private AutoExportScheduler $scheduler)
    {
    }

    public function saved(TourProduct $product): void
    {
        $this->scheduler->schedule();
    }

    public function deleted(TourProduct $product): void
    {
        $this->scheduler->schedule();
    }
}
