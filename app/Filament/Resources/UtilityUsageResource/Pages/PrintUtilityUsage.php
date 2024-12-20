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
        $this->record = UtilityUsageResource::getModel()::findOrFail($recordId);
    }
}
