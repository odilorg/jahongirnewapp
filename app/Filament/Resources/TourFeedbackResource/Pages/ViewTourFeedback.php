<?php

declare(strict_types=1);

namespace App\Filament\Resources\TourFeedbackResource\Pages;

use App\Filament\Resources\TourFeedbackResource;
use Filament\Resources\Pages\ViewRecord;

class ViewTourFeedback extends ViewRecord
{
    protected static string $resource = TourFeedbackResource::class;

    // No header actions — viewing must never mutate the row. A future
    // "mark as seen" phase would add an action here, gated to admin/super
    // admin only and writing to a dedicated column (out of scope today).
    protected function getHeaderActions(): array
    {
        return [];
    }
}
