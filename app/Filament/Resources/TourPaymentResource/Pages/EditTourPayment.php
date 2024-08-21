<?php

namespace App\Filament\Resources\TourPaymentResource\Pages;

use App\Filament\Resources\TourPaymentResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTourPayment extends EditRecord
{
    protected static string $resource = TourPaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
