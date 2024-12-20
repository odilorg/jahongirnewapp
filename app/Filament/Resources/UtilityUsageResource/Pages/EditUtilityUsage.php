<?php

namespace App\Filament\Resources\UtilityUsageResource\Pages;

use App\Filament\Resources\UtilityUsageResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditUtilityUsage extends EditRecord
{
    protected static string $resource = UtilityUsageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
