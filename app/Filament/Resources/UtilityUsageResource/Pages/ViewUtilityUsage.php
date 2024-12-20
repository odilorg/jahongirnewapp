<?php

namespace App\Filament\Resources\UtilityUsageResource\Pages;

use App\Filament\Resources\UtilityUsageResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewUtilityUsage extends ViewRecord
{
    protected static string $resource = UtilityUsageResource::class;

    // Corrected Blade view path (without .blade extension)
    protected static string $view = 'filament.resources.utility-usages.pages.view-utility-usage';

    
}
