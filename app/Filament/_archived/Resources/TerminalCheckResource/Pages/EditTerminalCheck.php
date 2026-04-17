<?php

namespace App\Filament\Resources\TerminalCheckResource\Pages;

use App\Filament\Resources\TerminalCheckResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTerminalCheck extends EditRecord
{
    protected static string $resource = TerminalCheckResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
