<?php

namespace App\Filament\Resources\ChatidResource\Pages;

use App\Filament\Resources\ChatidResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditChatid extends EditRecord
{
    protected static string $resource = ChatidResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
