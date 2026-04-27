<?php

namespace App\Filament\Resources\UtilityUsageResource\Pages;

use App\Filament\Resources\UtilityUsageResource;
use Filament\Resources\Pages\Page;

class PrintUtilityUsage extends Page
{
    protected static string $resource = UtilityUsageResource::class;

    protected static string $view = 'filament.resources.utility-usages.pages.print-utility-usage';

    public $record;

    public function mount($recordId)
    {
        // The print Blade reads $record->hotel, ->meter, ->utility — eager
        // load them in one query so the page never N+1s on the way to the
        // printer.
        $this->record = UtilityUsageResource::getModel()::query()
            ->with(['hotel', 'meter', 'utility'])
            ->findOrFail($recordId);
    }
}
