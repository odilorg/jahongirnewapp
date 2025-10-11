<?php

namespace App\Filament\Resources\TelegramBotConversationResource\Pages;

use App\Filament\Resources\TelegramBotConversationResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTelegramBotConversation extends EditRecord
{
    protected static string $resource = TelegramBotConversationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
