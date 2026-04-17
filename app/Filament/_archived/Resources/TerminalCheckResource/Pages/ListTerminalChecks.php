<?php

namespace App\Filament\Resources\TerminalCheckResource\Pages;

use App\Filament\Resources\TerminalCheckResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTerminalChecks extends ListRecords
{
    protected static string $resource = TerminalCheckResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
