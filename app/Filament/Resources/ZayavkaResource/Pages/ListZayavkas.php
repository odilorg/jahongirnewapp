<?php

namespace App\Filament\Resources\ZayavkaResource\Pages;

use App\Filament\Resources\ZayavkaResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListZayavkas extends ListRecords
{
    protected static string $resource = ZayavkaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
