<?php

namespace App\Filament\Resources\TourExpenseResource\Pages;

use App\Filament\Resources\TourExpenseResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTourExpenses extends ListRecords
{
    protected static string $resource = TourExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
