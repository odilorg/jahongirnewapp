<?php

namespace App\Filament\Resources\BotConfigurationResource\Pages;

use App\Filament\Resources\BotConfigurationResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditBotConfiguration extends EditRecord
{
    protected static string $resource = BotConfigurationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
