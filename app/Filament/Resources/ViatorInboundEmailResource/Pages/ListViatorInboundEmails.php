<?php

declare(strict_types=1);

namespace App\Filament\Resources\ViatorInboundEmailResource\Pages;

use App\Filament\Resources\ViatorInboundEmailResource;
use Filament\Resources\Pages\ListRecords;

class ListViatorInboundEmails extends ListRecords
{
    protected static string $resource = ViatorInboundEmailResource::class;

    // No header actions — events are guest-driven, never created here.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
