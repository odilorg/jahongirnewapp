<?php

namespace App\Filament\Resources\DriverResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SupplierPaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'supplier_payments';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('sold_tour_id')
              //  ->relationship('sold_tour', 'group_name')
                ->preload()
                ->searchable()
                ->required()
                ->relationship('sold_tour', 'group_name', function (Builder $query) {
                    $driverId = $this->ownerRecord->id; // Get the current driver_id from the driver record
                    $query->whereHas('driver', function ($query) use ($driverId) {
                        $query->where('driver.id', $driverId); // Filter sold tours by driver_id
                    });
                }),

            // Forms\Components\Select::make('driver_id')
            //     ->relationship(name: 'driver', titleAttribute: 'full_name')
            //     ->preload()
            //     ->searchable(),

            // Forms\Components\Select::make('guide_id')
            //     ->relationship(name: 'guide', titleAttribute: 'full_name')
            //     ->preload()
            //     ->searchable(),

            Forms\Components\TextInput::make('amount_paid')
                ->required()
                ->numeric(),
            Forms\Components\DatePicker::make('payment_date')
                ->native(false)
                ->displayFormat('d/m/Y'),
            //    ->maxLength(255),
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

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('payment_type')
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
           
           
            Tables\Columns\TextColumn::make('amount_paid')
                ->numeric()
                ->money('USD')
                ->sortable(),
            Tables\Columns\TextColumn::make('payment_date')
                ->date()
                ->sortable(),
             Tables\Columns\TextColumn::make('payment_type')
                ->sortable(),  
                Tables\Columns\ImageColumn::make('receipt_image')
                ->circular(),     
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
