<?php

namespace App\Filament\Resources\CashierShiftResource\Pages;

use App\Enums\Currency;
use App\Enums\TransactionType;
use App\Filament\Resources\CashierShiftResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Support\Enums\FontWeight;

class ViewCashierShift extends ViewRecord
{
    protected static string $resource = CashierShiftResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Shift Information')
                    ->schema([
                        Infolists\Components\TextEntry::make('id')
                            ->label('Shift ID')
                            ->badge()
                            ->color('primary'),
                        Infolists\Components\TextEntry::make('user.name')
                            ->label('Cashier')
                            ->badge()
                            ->color('info'),
                        Infolists\Components\TextEntry::make('cashDrawer.name')
                            ->label('Cash Drawer')
                            ->badge()
                            ->color('success'),
                        Infolists\Components\TextEntry::make('status')
                            ->badge()
                            ->color(fn ($state) => $state->value === 'open' ? 'success' : 'gray'),
                        Infolists\Components\TextEntry::make('opened_at')
                            ->label('Opened At')
                            ->dateTime(),
                        Infolists\Components\TextEntry::make('closed_at')
                            ->label('Closed At')
                            ->dateTime()
                            ->visible(fn ($record) => $record->status->value === 'closed'),
                        Infolists\Components\TextEntry::make('duration')
                            ->label('Duration')
                            ->getStateUsing(function ($record) {
                                if ($record->status->value === 'open') {
                                    return $record->opened_at->diffForHumans();
                                } else {
                                    return $record->opened_at->diffForHumans($record->closed_at, true);
                                }
                            })
                            ->badge()
                            ->color('warning'),
                    ])
                    ->columns(3),

                Infolists\Components\Section::make('Beginning Cash Amounts')
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('beginningSaldos')
                            ->schema([
                                Infolists\Components\TextEntry::make('currency')
                                    ->badge()
                                    ->color('primary'),
                                Infolists\Components\TextEntry::make('amount')
                                    ->label('Amount')
                                    ->money(fn ($record) => $record->currency->value)
                                    ->weight(FontWeight::Bold),
                            ])
                            ->columns(2)
                            ->visible(fn ($record) => $record->beginningSaldos->isNotEmpty()),
                        Infolists\Components\TextEntry::make('beginning_saldo')
                            ->label('Legacy UZS Beginning Saldo')
                            ->money('UZS')
                            ->weight(FontWeight::Bold)
                            ->visible(fn ($record) => $record->beginning_saldo > 0 && $record->beginningSaldos->isEmpty()),
                    ]),

                Infolists\Components\Section::make('Transaction Summary')
                    ->schema([
                        Infolists\Components\TextEntry::make('transactions_count')
                            ->label('Total Transactions')
                            ->getStateUsing(fn ($record) => $record->transactions->count())
                            ->badge()
                            ->color('info'),
                        Infolists\Components\TextEntry::make('cash_in_count')
                            ->label('Cash In Transactions')
                            ->getStateUsing(fn ($record) => $record->transactions->whereIn('type', [TransactionType::IN, TransactionType::IN_OUT])->count())
                            ->badge()
                            ->color('success'),
                        Infolists\Components\TextEntry::make('cash_out_count')
                            ->label('Cash Out Transactions')
                            ->getStateUsing(fn ($record) => $record->transactions->whereIn('type', [TransactionType::OUT, TransactionType::IN_OUT])->count())
                            ->badge()
                            ->color('danger'),
                        Infolists\Components\TextEntry::make('complex_transactions_count')
                            ->label('Complex Transactions')
                            ->getStateUsing(fn ($record) => $record->transactions->where('type', TransactionType::IN_OUT)->count())
                            ->badge()
                            ->color('warning'),
                    ])
                    ->columns(4),

                Infolists\Components\Section::make('Multi-Currency Balances')
                    ->schema([
                        Infolists\Components\TextEntry::make('currency_balances')
                            ->label('')
                            ->html()
                            ->getStateUsing(function ($record) {
                                // Get currencies from transactions
                                $transactionCurrencies = $record->getUsedCurrencies();
                                $beginningSaldoCurrencies = $record->beginningSaldos->pluck('currency');
                                $allCurrencies = $transactionCurrencies->merge($beginningSaldoCurrencies)->unique();
                                
                                // Also include UZS if there's a legacy beginning_saldo
                                if ($record->beginning_saldo > 0) {
                                    $allCurrencies = $allCurrencies->push(Currency::UZS);
                                }
                                
                                if ($allCurrencies->isEmpty()) {
                                    return '<p class="text-gray-500">No currencies used in this shift</p>';
                                }
                                
                                $html = '<div class="overflow-x-auto"><table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">';
                                $html .= '<thead class="bg-gray-50 dark:bg-gray-800"><tr>';
                                $html .= '<th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Currency</th>';
                                $html .= '<th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Beginning</th>';
                                $html .= '<th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Cash In</th>';
                                $html .= '<th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Cash Out</th>';
                                $html .= '<th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Net Balance</th>';
                                $html .= '</tr></thead><tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">';
                                
                                foreach ($allCurrencies as $currency) {
                                    $beginning = $record->getBeginningSaldoForCurrency($currency);
                                    $cashIn = $record->getTotalCashInForCurrency($currency);
                                    $cashOut = $record->getTotalCashOutForCurrency($currency);
                                    $netBalance = $record->getNetBalanceForCurrency($currency);
                                    
                                    $netBalanceColor = $netBalance >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400';
                                    
                                    $html .= '<tr>';
                                    $html .= '<td class="px-6 py-4 whitespace-nowrap"><span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">' . $currency->value . '</span></td>';
                                    $html .= '<td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900 dark:text-gray-100">' . $currency->formatAmount($beginning) . '</td>';
                                    $html .= '<td class="px-6 py-4 whitespace-nowrap text-sm text-green-600 dark:text-green-400">' . $currency->formatAmount($cashIn) . '</td>';
                                    $html .= '<td class="px-6 py-4 whitespace-nowrap text-sm text-red-600 dark:text-red-400">' . $currency->formatAmount($cashOut) . '</td>';
                                    $html .= '<td class="px-6 py-4 whitespace-nowrap text-sm font-semibold ' . $netBalanceColor . '">' . $currency->formatAmount($netBalance) . '</td>';
                                    $html .= '</tr>';
                                }
                                
                                $html .= '</tbody></table></div>';
                                return $html;
                            }),
                    ]),
            ]);
    }
}
