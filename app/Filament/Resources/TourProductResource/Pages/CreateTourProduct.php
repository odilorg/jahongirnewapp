<?php

declare(strict_types=1);

namespace App\Filament\Resources\TourProductResource\Pages;

use App\Filament\Resources\TourProductResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTourProduct extends CreateRecord
{
    protected static string $resource = TourProductResource::class;

    protected function getRedirectUrl(): string
    {
        // Drop the operator on the edit page after create so they can
        // immediately add price tiers via the relation manager (which
        // is only mounted on edit, not create).
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
