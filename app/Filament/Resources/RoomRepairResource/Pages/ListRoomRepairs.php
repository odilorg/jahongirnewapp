<?php

namespace App\Filament\Resources\RoomRepairResource\Pages;

use App\Filament\Resources\RoomRepairResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListRoomRepairs extends ListRecords
{
    protected static string $resource = RoomRepairResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
