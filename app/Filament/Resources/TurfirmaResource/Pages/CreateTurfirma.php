<?php

namespace App\Filament\Resources\TurfirmaResource\Pages;

use App\Filament\Resources\TurfirmaResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateTurfirma extends CreateRecord
{
    protected static string $resource = TurfirmaResource::class;
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function afterFill($resource): void
    {
       dd($resource); // Runs after the form fields are populated with their default values.
    }
}
