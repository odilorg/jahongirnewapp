<?php

namespace App\Filament\Resources\ZayavkaResource\Pages;

use App\Filament\Resources\ZayavkaResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditZayavka extends EditRecord
{
    protected static string $resource = ZayavkaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
