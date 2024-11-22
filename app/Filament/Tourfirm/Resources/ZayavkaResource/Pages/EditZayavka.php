<?php

namespace App\Filament\Tourfirm\Resources\ZayavkaResource\Pages;

use App\Filament\Tourfirm\Resources\ZayavkaResource;
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
