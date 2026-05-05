<?php

declare(strict_types=1);

namespace App\Filament\Resources\ExpenseCategoryResource\Pages;

use App\Filament\Resources\ExpenseCategoryResource;
use Filament\Resources\Pages\EditRecord;

class EditExpenseCategory extends EditRecord
{
    protected static string $resource = ExpenseCategoryResource::class;

    // Hard-delete is disabled at the resource level; no delete action
    // shown here. Use is_active=false to retire a category.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
