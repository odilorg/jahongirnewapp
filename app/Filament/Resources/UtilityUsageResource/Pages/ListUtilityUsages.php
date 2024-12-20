<?php

namespace App\Filament\Resources\UtilityUsageResource\Pages;

use App\Filament\Resources\UtilityUsageResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListUtilityUsages extends ListRecords
{
    protected static string $resource = UtilityUsageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
