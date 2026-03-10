<?php

namespace App\Filament\Resources\CashExpenseResource\Pages;

use App\Filament\Resources\CashExpenseResource;
use Filament\Resources\Pages\ListRecords;

class ListCashExpenses extends ListRecords
{
    protected static string $resource = CashExpenseResource::class;
}
