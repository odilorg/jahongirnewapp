<?php

namespace App\Filament\Resources\CashierShiftResource\Pages;

use App\Filament\Resources\CashierShiftResource;
use App\Models\CashierShift;
use Filament\Resources\Pages\Page;

class ShiftReport extends Page
{
    protected static string $resource = CashierShiftResource::class;

    protected static string $view = 'filament.resources.cashier-shift-resource.pages.shift-report';

    protected static ?string $title = 'Shift Report';

    public CashierShift $record;

    public function mount(int | string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    protected function resolveRecord(int | string $key): CashierShift
    {
        return CashierShift::findOrFail($key);
    }

    public function getTransactions()
    {
        return $this->record->transactions()->orderBy('occurred_at')->get();
    }

    public function getCashCount()
    {
        return $this->record->cashCount;
    }
}