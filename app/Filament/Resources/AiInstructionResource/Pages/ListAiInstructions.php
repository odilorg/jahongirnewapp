<?php

namespace App\Filament\Resources\AiInstructionResource\Pages;

use App\Filament\Resources\AiInstructionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAiInstructions extends ListRecords
{
    protected static string $resource = AiInstructionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
