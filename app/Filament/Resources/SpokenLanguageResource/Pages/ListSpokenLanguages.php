<?php

namespace App\Filament\Resources\SpokenLanguageResource\Pages;

use App\Filament\Resources\SpokenLanguageResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSpokenLanguages extends ListRecords
{
    protected static string $resource = SpokenLanguageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
