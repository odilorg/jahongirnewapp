<?php

namespace App\Filament\Resources\CashierShiftResource\Pages;

use App\Filament\Resources\CashierShiftResource;
use App\Enums\ShiftStatus;
use App\Enums\TransactionType;
use Filament\Resources\Pages\ViewRecord;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Support\Enums\FontWeight;

class ViewCashierShift extends ViewRecord
{
    protected static string $resource = CashierShiftResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Shift Information')
                    ->schema([
                        Infolists\Components\TextEntry::make('cashDrawer.name')
                            ->label('Cash Drawer')
                            ->badge()
                            ->color('primary'),
                        Infolists\Components\TextEntry::make('user.name')
                            ->label('Cashier')
                            ->badge()
                            ->color('info'),
                        Infolists\Components\TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->color(fn (ShiftStatus $state): string => match ($state) {
                                ShiftStatus::OPEN => 'success',
                                ShiftStatus::CLOSED => 'gray',
                            }),
                    ])
                    ->columns(3),

                Infolists\Components\Section::make('Cash Summary')
                    ->schema([
                        Infolists\Components\TextEntry::make('beginning_saldo')
                            ->label('Beginning Saldo')
                            ->money('UZS')
                            ->weight(FontWeight::Bold),
                        Infolists\Components\TextEntry::make('expected_end_saldo')
                            ->label('Expected End Saldo')
                            ->money('UZS')
                            ->weight(FontWeight::Bold),
                        Infolists\Components\TextEntry::make('counted_end_saldo')
                            ->label('Counted End Saldo')
                            ->money('UZS')
                            ->weight(FontWeight::Bold)
                            ->visible(fn ($record) => $record->status === ShiftStatus::CLOSED),
                    ])
                    ->columns(3),

                Infolists\Components\Section::make('Transaction Summary')
                    ->schema([
                        Infolists\Components\TextEntry::make('transactions_count')
                            ->label('Total Transactions')
                            ->getStateUsing(fn ($record) => $record->transactions->count())
                            ->badge()
                            ->color('info'),
                        Infolists\Components\TextEntry::make('cash_in_count')
                            ->label('Cash In Transactions')
                            ->getStateUsing(fn ($record) => $record->transactions->where('type', TransactionType::IN)->count())
                            ->badge()
                            ->color('success'),
                        Infolists\Components\TextEntry::make('cash_out_count')
                            ->label('Cash Out Transactions')
                            ->getStateUsing(fn ($record) => $record->transactions->where('type', TransactionType::OUT)->count())
                            ->badge()
                            ->color('danger'),
                    ])
                    ->columns(3),

                Infolists\Components\Section::make('Discrepancy Information')
                    ->schema([
                        Infolists\Components\TextEntry::make('discrepancy')
                            ->label('Discrepancy')
                            ->money('UZS')
                            ->weight(FontWeight::Bold)
                            ->color(fn ($state) => $state > 0 ? 'success' : ($state < 0 ? 'danger' : 'gray'))
                            ->visible(fn ($record) => $record->status === ShiftStatus::CLOSED && $record->discrepancy !== null),
                        Infolists\Components\TextEntry::make('discrepancy_reason')
                            ->label('Discrepancy Reason')
                            ->visible(fn ($record) => $record->status === ShiftStatus::CLOSED && !empty($record->discrepancy_reason)),
                    ])
                    ->visible(fn ($record) => $record->status === ShiftStatus::CLOSED)
                    ->columns(2),

                Infolists\Components\Section::make('Timing')
                    ->schema([
                        Infolists\Components\TextEntry::make('opened_at')
                            ->label('Opened At')
                            ->dateTime(),
                        Infolists\Components\TextEntry::make('closed_at')
                            ->label('Closed At')
                            ->dateTime()
                            ->visible(fn ($record) => $record->status === ShiftStatus::CLOSED),
                        Infolists\Components\TextEntry::make('duration_in_hours')
                            ->label('Duration')
                            ->formatStateUsing(fn ($state) => $state ? number_format($state, 2) . ' hours' : 'N/A')
                            ->visible(fn ($record) => $record->status === ShiftStatus::CLOSED),
                    ])
                    ->columns(3),

                Infolists\Components\Section::make('Notes')
                    ->schema([
                        Infolists\Components\TextEntry::make('notes')
                            ->label('Shift Notes')
                            ->visible(fn ($record) => !empty($record->notes)),
                    ])
                    ->visible(fn ($record) => !empty($record->notes)),
            ]);
    }
}