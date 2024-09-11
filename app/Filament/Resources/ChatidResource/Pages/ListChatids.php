<?php

namespace App\Filament\Resources\ChatidResource\Pages;

use App\Filament\Resources\ChatidResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListChatids extends ListRecords
{
    protected static string $resource = ChatidResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
