<?php

namespace App\Filament\Resources\AuthorizedStaffResource\Pages;

use App\Filament\Resources\AuthorizedStaffResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAuthorizedStaff extends EditRecord
{
    protected static string $resource = AuthorizedStaffResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
