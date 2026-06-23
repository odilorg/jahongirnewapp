<?php

declare(strict_types=1);

namespace App\Filament\Resources\WaLeadCandidateResource\Pages;

use App\Filament\Resources\WaLeadCandidateResource;
use Filament\Resources\Pages\ListRecords;

class ListWaLeadCandidates extends ListRecords
{
    protected static string $resource = WaLeadCandidateResource::class;

    // No create header action — candidates come from the scan, not hand-created.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
