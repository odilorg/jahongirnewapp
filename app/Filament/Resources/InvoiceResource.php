<?php

namespace App\Filament\Resources;

use Filament\Forms;
use Filament\Tables;
use App\Models\Invoice;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;
use App\Filament\Resources\InvoiceResource\Pages;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\InvoiceResource\RelationManagers;

class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('turfirma_id')
                    ->relationship(name: 'turfirma', titleAttribute: 'name')
                    ->required()
                    ->preload()
                    ->searchable(),
                Forms\Components\Select::make('hotel_id')
                    ->relationship(name: 'hotel', titleAttribute: 'name')
                    ->required()
                    ->preload()
                    ->searchable(),
                Forms\Components\Select::make('zayavka_id')
                    ->relationship(name: 'zayavka', titleAttribute: 'name')
                    ->required()
                    ->preload()
                    ->searchable(),
                
                Forms\Components\TextInput::make('number')
                    ->required()
                    ->maxLength(255),
                    Forms\Components\DatePicker::make('invoice_date')
                    //->format('d/m/Y')
                    ->native(false)
                    ->closeOnDateSelection()
                    ->required(),
                Select::make('status')
                    ->options([
                        'paid' => 'Paid',
                        'not_paid' => 'Not Paid',
                        'partially' => 'Partially',
                    ]), 
                Forms\Components\TextInput::make('amount')
                    ->required()
                    ->numeric()
                    ->prefix('$'),       
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('hotel.name')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('turfirma.name')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('zayavka.name')
                    ->numeric()
                    ->sortable(),
              
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('number')
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->searchable(),
                Tables\Columns\TextColumn::make('invoice_date')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('amount')
                    ->money()
                    ->sortable(),    
            ])
            ->filters([
                //
            ])
            ->actions([
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
            'index' => Pages\ListInvoices::route('/'),
            'create' => Pages\CreateInvoice::route('/create'),
            'edit' => Pages\EditInvoice::route('/{record}/edit'),
        ];
    }
}
