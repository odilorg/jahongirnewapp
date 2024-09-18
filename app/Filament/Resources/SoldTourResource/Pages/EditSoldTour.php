<?php

namespace App\Filament\Resources\SoldTourResource\Pages;

use App\Filament\Resources\SoldTourResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSoldTour extends EditRecord
{
    protected static string $resource = SoldTourResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
