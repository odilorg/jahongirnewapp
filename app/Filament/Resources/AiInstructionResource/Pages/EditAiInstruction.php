<?php

namespace App\Filament\Resources\AiInstructionResource\Pages;

use App\Filament\Resources\AiInstructionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAiInstruction extends EditRecord
{
    protected static string $resource = AiInstructionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
