<?php

namespace App\Filament\Resources\TourBookingResource\Pages;

use App\Filament\Resources\TourBookingResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewTourBooking extends ViewRecord
{
    protected static string $resource = TourBookingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
