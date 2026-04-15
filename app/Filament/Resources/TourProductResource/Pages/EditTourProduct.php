<?php

declare(strict_types=1);

namespace App\Filament\Resources\TourProductResource\Pages;

use App\Filament\Resources\TourProductResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTourProduct extends EditRecord
{
    protected static string $resource = TourProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
