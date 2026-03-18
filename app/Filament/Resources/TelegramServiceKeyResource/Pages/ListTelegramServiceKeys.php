<?php

declare(strict_types=1);

namespace App\Filament\Resources\TelegramServiceKeyResource\Pages;

use App\Filament\Resources\TelegramServiceKeyResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTelegramServiceKeys extends ListRecords
{
    protected static string $resource = TelegramServiceKeyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Generate API Key'),
        ];
    }
}
