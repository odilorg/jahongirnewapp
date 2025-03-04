<?php

namespace App\Filament\Resources\GuestPaymentResource\Pages;

use App\Filament\Resources\GuestPaymentResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListGuestPayments extends ListRecords
{
    protected static string $resource = GuestPaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
