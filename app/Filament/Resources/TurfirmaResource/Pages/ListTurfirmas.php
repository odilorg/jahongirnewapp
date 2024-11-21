<?php

namespace App\Filament\Resources\TurfirmaResource\Pages;

use App\Filament\Resources\TurfirmaResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTurfirmas extends ListRecords
{
    protected static string $resource = TurfirmaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
