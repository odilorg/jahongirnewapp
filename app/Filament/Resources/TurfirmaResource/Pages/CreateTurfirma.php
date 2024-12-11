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

   
}
