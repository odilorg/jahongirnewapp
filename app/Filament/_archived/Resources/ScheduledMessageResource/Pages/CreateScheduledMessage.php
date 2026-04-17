<?php

namespace App\Filament\Resources\ScheduledMessageResource\Pages;

use App\Filament\Resources\ScheduledMessageResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateScheduledMessage extends CreateRecord
{
    protected static string $resource = ScheduledMessageResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
