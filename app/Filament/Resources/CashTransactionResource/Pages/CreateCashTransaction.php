<?php

namespace App\Filament\Resources\CashTransactionResource\Pages;

use App\Filament\Resources\CashTransactionResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateCashTransaction extends CreateRecord
{
    protected static string $resource = CashTransactionResource::class;
}
