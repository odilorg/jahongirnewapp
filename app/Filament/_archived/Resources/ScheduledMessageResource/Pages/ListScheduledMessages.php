<?php

namespace App\Filament\Resources\ScheduledMessageResource\Pages;

use App\Filament\Resources\ScheduledMessageResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListScheduledMessages extends ListRecords
{
    protected static string $resource = ScheduledMessageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
