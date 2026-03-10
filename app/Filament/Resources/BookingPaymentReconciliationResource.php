<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BookingPaymentReconciliationResource\Pages;
use App\Models\BookingPaymentReconciliation;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BookingPaymentReconciliationResource extends Resource
{
    protected static ?string $model = BookingPaymentReconciliation::class;
    protected static ?string $navigationIcon = 'heroicon-o-scale';
    protected static ?string $navigationGroup = 'Cash Management';
    protected static ?int $navigationSort = 6;
    protected static ?string $navigationLabel = 'Reconciliation';
    protected static ?string $modelLabel = 'Reconciliation';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('status')
                ->options([
                    'matched' => 'Matched',
                    'underpaid' => 'Underpaid',
                    'overpaid' => 'Overpaid',
                    'no_payment' => 'No Payment',
                    'no_booking' => 'No Booking',
                ]),
            Forms\Components\Textarea::make('resolution_notes')
                ->maxLength(1000),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('d M Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('beds24_booking_id')
                    ->label('Booking ID')
                    ->searchable()
                    ->copyable(),
                Tables\Columns\TextColumn::make('property_id')
                    ->label('Property')
                    ->formatStateUsing(fn ($state) => match ($state) {
                        '41097' => 'Jahongir Hotel',
                        '172793' => 'Jahongir Premium',
                        default => $state ?? '-',
                    }),
                Tables\Columns\TextColumn::make('expected_amount')
                    ->label('Expected')
                    ->money('usd')
                    ->sortable(),
                Tables\Columns\TextColumn::make('reported_amount')
                    ->label('Reported')
                    ->money('usd')
                    ->sortable(),
                Tables\Columns\TextColumn::make('discrepancy_amount')
                    ->label('Discrepancy')
                    ->money('usd')
                    ->color(fn ($state) => $state > 0 ? 'danger' : ($state < 0 ? 'warning' : 'success'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'matched' => 'success',
                        'underpaid', 'no_payment' => 'danger',
                        'overpaid' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('resolver.name')
                    ->label('Resolved By')
                    ->placeholder('-'),
                Tables\Columns\TextColumn::make('resolved_at')
                    ->label('Resolved')
                    ->dateTime('d M Y')
                    ->placeholder('-'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'matched' => 'Matched',
                        'underpaid' => 'Underpaid',
                        'overpaid' => 'Overpaid',
                        'no_payment' => 'No Payment',
                    ]),
                Tables\Filters\Filter::make('unresolved')
                    ->label('Unresolved Only')
                    ->query(fn (Builder $query) => $query->whereNull('resolved_at')->where('status', '!=', 'matched'))
                    ->toggle()
                    ->default(true),
                Tables\Filters\Filter::make('date_range')
                    ->form([
                        Forms\Components\DatePicker::make('from'),
                        Forms\Components\DatePicker::make('until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'], fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                            ->when($data['until'], fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('resolve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\Textarea::make('resolution_notes')
                            ->label('Resolution Notes')
                            ->required()
                            ->placeholder('Explain how this was resolved...'),
                    ])
                    ->visible(fn ($record) => !$record->resolved_at && $record->status !== 'matched')
                    ->action(function ($record, array $data) {
                        $record->update([
                            'resolved_by' => auth()->id(),
                            'resolved_at' => now(),
                            'resolution_notes' => $data['resolution_notes'],
                        ]);
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBookingPaymentReconciliations::route('/'),
        ];
    }
}
