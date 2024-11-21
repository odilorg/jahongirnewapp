<?php

namespace App\Filament\Resources\TurfirmaResource\Pages;

use App\Filament\Resources\TurfirmaResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTurfirma extends EditRecord
{
    protected static string $resource = TurfirmaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
