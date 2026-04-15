<?php

declare(strict_types=1);

namespace App\Filament\Resources\TourProductResource\Pages;

use App\Filament\Resources\TourProductResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTourProducts extends ListRecords
{
    protected static string $resource = TourProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('+ New tour'),
        ];
    }
}
