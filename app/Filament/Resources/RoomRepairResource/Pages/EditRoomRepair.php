<?php

namespace App\Filament\Resources\RoomRepairResource\Pages;

use App\Filament\Resources\RoomRepairResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditRoomRepair extends EditRecord
{
    protected static string $resource = RoomRepairResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
