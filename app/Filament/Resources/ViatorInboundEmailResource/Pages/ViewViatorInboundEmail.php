<?php

declare(strict_types=1);

namespace App\Filament\Resources\ViatorInboundEmailResource\Pages;

use App\Filament\Resources\ViatorInboundEmailResource;
use Filament\Resources\Pages\ViewRecord;

class ViewViatorInboundEmail extends ViewRecord
{
    protected static string $resource = ViatorInboundEmailResource::class;

    // Viewing must never mutate state in V1. "Approve amendment" /
    // "Apply cancellation" actions land in V2 once operators trust
    // the parser + diff fidelity.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
