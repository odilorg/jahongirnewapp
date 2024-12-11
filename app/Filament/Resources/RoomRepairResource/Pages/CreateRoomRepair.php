<?php

namespace App\Filament\Resources\RoomRepairResource\Pages;

use App\Filament\Resources\RoomRepairResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateRoomRepair extends CreateRecord
{
    protected static string $resource = RoomRepairResource::class;
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
