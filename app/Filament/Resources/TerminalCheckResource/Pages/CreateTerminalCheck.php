<?php

namespace App\Filament\Resources\TerminalCheckResource\Pages;

use App\Filament\Resources\TerminalCheckResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateTerminalCheck extends CreateRecord
{
    protected static string $resource = TerminalCheckResource::class;
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
