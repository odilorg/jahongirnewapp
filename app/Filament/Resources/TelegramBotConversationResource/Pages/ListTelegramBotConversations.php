<?php

namespace App\Filament\Resources\TelegramBotConversationResource\Pages;

use App\Filament\Resources\TelegramBotConversationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTelegramBotConversations extends ListRecords
{
    protected static string $resource = TelegramBotConversationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
