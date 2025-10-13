<?php

namespace App\Filament\Resources\AuthorizedStaffResource\Pages;

use App\Filament\Resources\AuthorizedStaffResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAuthorizedStaff extends ListRecords
{
    protected static string $resource = AuthorizedStaffResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
