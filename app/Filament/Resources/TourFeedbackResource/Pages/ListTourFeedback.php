<?php

declare(strict_types=1);

namespace App\Filament\Resources\TourFeedbackResource\Pages;

use App\Filament\Resources\TourFeedbackResource;
use Filament\Resources\Pages\ListRecords;

class ListTourFeedback extends ListRecords
{
    protected static string $resource = TourFeedbackResource::class;

    // No header actions — feedback is guest-authored, never created from
    // the admin panel.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
