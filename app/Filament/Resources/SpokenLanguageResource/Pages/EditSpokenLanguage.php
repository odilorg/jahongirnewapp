<?php

namespace App\Filament\Resources\SpokenLanguageResource\Pages;

use App\Filament\Resources\SpokenLanguageResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSpokenLanguage extends EditRecord
{
    protected static string $resource = SpokenLanguageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
