<?php

namespace App\Filament\Widgets;

use App\Enums\Currency;
use App\Models\CashDrawer;
use App\Models\CashierShift;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class DrawerBalanceWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $drawers = CashDrawer::active()->get();
        $stats = [];
        
        foreach ($drawers as $drawer) {
            $openShifts = $drawer->openShifts()->with('transactions')->get();
            $hasOpenShift = $openShifts->isNotEmpty();
            
                   // Calculate balances by currency
                   $balancesByCurrency = [];
                   foreach ($openShifts as $shift) {
                       $usedCurrencies = $shift->getUsedCurrencies();
                       foreach ($usedCurrencies as $currency) {
                           $netBalance = $shift->getNetBalanceForCurrency($currency);
                           
                           if (!isset($balancesByCurrency[$currency->value])) {
                               $balancesByCurrency[$currency->value] = [
                                   'currency' => $currency,
                                   'balance' => 0,
                                   'shifts' => 0
                               ];
                           }
                           $balancesByCurrency[$currency->value]['balance'] += $netBalance;
                           $balancesByCurrency[$currency->value]['shifts']++;
                       }
                   }
            
            // Create description with multi-currency info
            $description = $hasOpenShift ? 'Open shifts: ' : 'No open shifts';
            if ($hasOpenShift) {
                $currencyInfo = [];
                foreach ($balancesByCurrency as $data) {
                    $currencyInfo[] = $data['currency']->formatAmount($data['balance']);
                }
                $description .= implode(', ', $currencyInfo);
            }
            
            // Use the primary currency for the main stat (or UZS if no open shifts)
            $primaryCurrency = ($hasOpenShift && !empty($balancesByCurrency)) ? 
                $balancesByCurrency[array_key_first($balancesByCurrency)]['currency'] : 
                Currency::UZS;
            $primaryBalance = ($hasOpenShift && !empty($balancesByCurrency)) ? 
                $balancesByCurrency[array_key_first($balancesByCurrency)]['balance'] : 
                0;
            
            $stats[] = Stat::make($drawer->name, $primaryCurrency->formatAmount($primaryBalance))
                ->description($description)
                ->descriptionIcon($hasOpenShift ? 'heroicon-o-play' : 'heroicon-o-stop')
                ->color($hasOpenShift ? 'success' : 'gray')
                ->extraAttributes([
                    'class' => 'cursor-pointer',
                ]);
        }
        
        return $stats;
    }
}
