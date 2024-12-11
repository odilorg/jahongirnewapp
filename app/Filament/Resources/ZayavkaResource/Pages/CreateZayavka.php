<?php

namespace App\Filament\Resources\ZayavkaResource\Pages;

use App\Filament\Resources\ZayavkaResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateZayavka extends CreateRecord
{
    protected static string $resource = ZayavkaResource::class;

    protected function afterFill($resource): void
    {
       dd($resource); // Runs after the form fields are populated with their default values.
    }
}
