<?php

namespace App\Filament\Resources\RoompriceResource\Pages;

use App\Filament\Resources\RoompriceResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListRoomprices extends ListRecords
{
    protected static string $resource = RoompriceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
