<?php

namespace App\Filament\Resources\BookingResource\RelationManagers;

use Filament\Forms;
use Filament\Tables;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Tables\Columns\SelectColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Resources\RelationManagers\RelationManager;

class GuestPaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'GuestPayments';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('guest_id')
                ->label('Guest')
                ->relationship('guest', 'full_name')
                ->reactive(), // <-- important to trigger changes

           
                Forms\Components\TextInput::make('amount')
                ->numeric()
                ->prefix('$')
                ->maxValue(10000)
                ->minValue(0), 
                Forms\Components\DatePicker::make('payment_date')
                    ->native(false)
                    ->required(),
                    Forms\Components\Select::make('payment_method')
                    ->required()
                    ->options([
                        'cash' => 'Cash',
                        'card' => 'Card',
                        'paypal' => 'PayPal',
                        'bank' => 'Bank Transfer',
                        'stripe' => 'Stripe',
                    ])
                    ->default('not paid')  // Set the default option to 'Not Paid'
                    ->label('Payment Method'),
                    Forms\Components\Select::make('payment_status')
                    ->required()
                    ->options([
                        'paid' => 'Paid',
                        'not_paid' => 'Not Paid',
                        'partially_paid' => 'Partially Paid',
                    ])
                    ->default('not paid')  // Set the default option to 'Not Paid'
                    ->label('Payment Status'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                 Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('guest.full_name')
                    ->sortable(),
                Tables\Columns\TextColumn::make('booking.tour.title')
                    ->sortable(),
                Tables\Columns\TextColumn::make('amount')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('payment_date')
                    ->date()
                    ->sortable(),
                    SelectColumn::make('payment_method')
                    ->options([
                        'cash' => 'Cash',
                        'card' => 'Card',
                        'paypal' => 'PayPal',
                        'bank' => 'Bank Transfer',
                        'stripe' => 'Stripe',
                    ]),
                    SelectColumn::make('payment_status')
                    ->options([
                        'paid' => 'Paid',
                        'not_paid' => 'Not Paid',
                        'partially_paid' => 'Partially Paid',
                    ]),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
