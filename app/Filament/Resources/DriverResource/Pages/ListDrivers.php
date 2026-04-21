<?php

namespace App\Filament\Resources\DriverResource\Pages;

use App\Filament\Resources\DriverResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDrivers extends ListRecords
{
    protected static string $resource = DriverResource::class;

    // Create action moved into the table header (inline with search)
    // so the big top-right CTA doesn't float above the list viewport.
    protected function getHeaderActions(): array
    {
        return [];
    }

    // Drop the redundant "List" breadcrumb segment; cluster > resource
    // is sufficient context on a list page.
    public function getBreadcrumb(): ?string
    {
        return null;
    }
}
