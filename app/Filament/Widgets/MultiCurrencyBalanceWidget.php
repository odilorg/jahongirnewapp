<?php

namespace App\Filament\Widgets;

use App\Enums\Currency;
use App\Models\CashDrawer;
use Filament\Widgets\Widget;

class MultiCurrencyBalanceWidget extends Widget
{
    protected static string $view = 'filament.widgets.multi-currency-balance-widget';
    
    protected int | string | array $columnSpan = 'full';

    public function getViewData(): array
    {
        $drawers = CashDrawer::active()->with(['openShifts.transactions'])->get();
        $drawerData = [];
        
        foreach ($drawers as $drawer) {
            $openShifts = $drawer->openShifts;
            $balancesByCurrency = [];
            
            foreach ($openShifts as $shift) {
                $usedCurrencies = $shift->getUsedCurrencies();
                foreach ($usedCurrencies as $currency) {
                    $netBalance = $shift->getNetBalanceForCurrency($currency);
                    
                    if (!isset($balancesByCurrency[$currency->value])) {
                        $balancesByCurrency[$currency->value] = [
                            'currency' => $currency,
                            'balance' => 0,
                            'shifts' => 0,
                            'transactions' => 0
                        ];
                    }
                    
                    $balancesByCurrency[$currency->value]['balance'] += $netBalance;
                    $balancesByCurrency[$currency->value]['shifts']++;
                    $balancesByCurrency[$currency->value]['transactions'] += $shift->transactions->where('currency', $currency)->count();
                }
            }
            
            $drawerData[] = [
                'drawer' => $drawer,
                'hasOpenShifts' => $openShifts->isNotEmpty(),
                'balances' => $balancesByCurrency,
                'totalShifts' => $openShifts->count(),
            ];
        }
        
        return [
            'drawers' => $drawerData,
            'allCurrencies' => Currency::cases(),
        ];
    }
}
