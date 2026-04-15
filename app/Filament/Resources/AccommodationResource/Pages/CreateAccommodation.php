<?php

declare(strict_types=1);

namespace App\Filament\Resources\AccommodationResource\Pages;

use App\Filament\Resources\AccommodationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAccommodation extends CreateRecord
{
    protected static string $resource = AccommodationResource::class;
}
