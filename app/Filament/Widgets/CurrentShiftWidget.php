<?php

namespace App\Filament\Widgets;

use App\Models\CashierShift;
use App\Models\User;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;
use Filament\Notifications\Notification;

class CurrentShiftWidget extends Widget
{
    protected static string $view = 'filament.widgets.current-shift-widget';

    protected int | string | array $columnSpan = 'full';

    public function getViewData(): array
    {
        $user = Auth::user();
        $currentShift = $user->getCurrentOpenShift();

        return [
            'currentShift' => $currentShift,
            'hasOpenShift' => $currentShift !== null,
            'totalCashIn' => $currentShift?->total_cash_in ?? 0,
            'totalCashOut' => $currentShift?->total_cash_out ?? 0,
            'expectedBalance' => $currentShift?->expected_end_saldo ?? 0,
        ];
    }

    public function startNewShift(): void
    {
        $this->redirect('/admin/cashier-shifts/start-shift');
    }
}


