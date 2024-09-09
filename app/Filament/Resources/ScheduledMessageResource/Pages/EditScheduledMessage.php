<?php

namespace App\Filament\Resources\ScheduledMessageResource\Pages;

use App\Filament\Resources\ScheduledMessageResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditScheduledMessage extends EditRecord
{
    protected static string $resource = ScheduledMessageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
