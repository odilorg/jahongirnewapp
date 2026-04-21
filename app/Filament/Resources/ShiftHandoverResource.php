<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ShiftHandoverResource\Pages;
use App\Models\ShiftHandover;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ShiftHandoverResource extends Resource
{
    protected static ?string $model = ShiftHandover::class;
    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';
    protected static ?string $navigationGroup = 'Finance';
    protected static ?int $navigationSort = 5;
    protected static ?string $navigationLabel = 'Shift Handovers';
    protected static ?string $modelLabel = 'Shift Handover';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Textarea::make('discrepancy_notes')->maxLength(1000),
            Forms\Components\Textarea::make('resolution_notes')->maxLength(1000),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('outgoingShift.user.name')
                    ->label('Cashier'),
                Tables\Columns\TextColumn::make('uzs_summary')
                    ->label('UZS')
                    ->getStateUsing(fn ($record) => 'Exp: ' . number_format($record->expected_uzs) . ' / Cnt: ' . number_format($record->counted_uzs)),
                Tables\Columns\TextColumn::make('usd_summary')
                    ->label('USD')
                    ->getStateUsing(fn ($record) => 'Exp: $' . number_format($record->expected_usd, 2) . ' / Cnt: $' . number_format($record->counted_usd, 2)),
                Tables\Columns\TextColumn::make('eur_summary')
                    ->label('EUR')
                    ->getStateUsing(fn ($record) => number_format($record->expected_eur, 2) == 0 && number_format($record->counted_eur, 2) == 0 ? '-' : 'Exp: €' . number_format($record->expected_eur, 2) . ' / Cnt: €' . number_format($record->counted_eur, 2)),
                Tables\Columns\IconColumn::make('has_discrepancy')
                    ->label('Discrepancy')
                    ->getStateUsing(fn ($record) => $record->hasDiscrepancy())
                    ->boolean()
                    ->trueIcon('heroicon-o-exclamation-triangle')
                    ->falseIcon('heroicon-o-check-circle')
                    ->trueColor('danger')
                    ->falseColor('success'),
                Tables\Columns\TextColumn::make('discrepancy_notes')
                    ->label('Notes')
                    ->limit(30)
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\Filter::make('discrepancies_only')
                    ->label('Discrepancies Only')
                    ->query(function ($query) {
                        return $query->where(function ($q) {
                            $q->whereRaw('ABS(counted_uzs - expected_uzs) > 100')
                              ->orWhereRaw('ABS(counted_usd - expected_usd) > 0.5')
                              ->orWhereRaw('ABS(counted_eur - expected_eur) > 0.5');
                        });
                    })
                    ->toggle(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListShiftHandovers::route('/'),
        ];
    }
}
