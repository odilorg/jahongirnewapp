<?php

namespace App\Filament\Resources\GuideResource\RelationManagers;

use App\Filament\RelationManagers\SupplierPaymentsRelationManager;

class PaymentsRelationManager extends SupplierPaymentsRelationManager
{
    protected string $supplierType = 'guide';
}
