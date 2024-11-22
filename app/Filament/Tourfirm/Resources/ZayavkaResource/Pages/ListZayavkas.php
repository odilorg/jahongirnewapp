<?php

namespace App\Filament\Tourfirm\Resources\ZayavkaResource\Pages;

use App\Filament\Tourfirm\Resources\ZayavkaResource;
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
