<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupplierPaymentResource\Pages;
use App\Filament\Resources\SupplierPaymentResource\RelationManagers;
use App\Models\SupplierPayment;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SupplierPaymentResource extends Resource
{
    protected static ?string $model = SupplierPayment::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('sold_tour_id')
                    ->relationship(name: 'sold_tour', titleAttribute: 'group_name')
                    ->preload()
                    ->searchable()
                    ->required()
                    ->reactive() // Make the field reactive to trigger updates
                    ->afterStateUpdated(function (callable $set, $state) {
                        // When the sold_tour_id is updated, fetch driver and guide details
                        if ($state) {
                            $soldTour = \App\Models\SoldTour::find($state);

                            if ($soldTour) {
                                $set('driver_id', $soldTour->driver_id);
                                $set('guide_id', $soldTour->guide_id);
                            }
                        }
                    }),

                Forms\Components\Select::make('driver_id')
                    ->relationship(name: 'driver', titleAttribute: 'full_name')
                    ->preload()
                    ->searchable(),

                Forms\Components\Select::make('guide_id')
                    ->relationship(name: 'guide', titleAttribute: 'full_name')
                    ->preload()
                    ->searchable(),

                Forms\Components\TextInput::make('amount_paid')
                    ->required()
                    ->numeric(),

                Forms\Components\DatePicker::make('payment_date')
                    ->native(false)
                    ->displayFormat('d/m/Y'),

                Forms\Components\Select::make('payment_type')
                    ->options([
                        'cash' => 'Cash',
                        'Perevod' => 'Perevod',
                        'card' => 'Card',
                    ]),

                Forms\Components\FileUpload::make('receipt_image')
                    ->image(),

            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('sold_tour.group_name')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('driver.full_name')
                    ->numeric()
                    ->placeholder('No driver')
                    ->sortable(),
                Tables\Columns\TextColumn::make('guide.full_name')
                    ->numeric()
                    ->placeholder('No guide')
                    ->sortable(),
                Tables\Columns\TextColumn::make('amount_paid')
                    ->numeric()
                    ->money('USD')
                    ->sortable(),
                Tables\Columns\TextColumn::make('payment_date')
                    ->date()
                    ->sortable(),
                Tables\Columns\ImageColumn::make('receipt_image')
                    ->circular(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSupplierPayments::route('/'),
            'create' => Pages\CreateSupplierPayment::route('/create'),
            'edit' => Pages\EditSupplierPayment::route('/{record}/edit'),
        ];
    }
}
