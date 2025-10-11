<?php

namespace App\Filament\Resources\BotConfigurationResource\Pages;

use App\Filament\Resources\BotConfigurationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBotConfigurations extends ListRecords
{
    protected static string $resource = BotConfigurationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
