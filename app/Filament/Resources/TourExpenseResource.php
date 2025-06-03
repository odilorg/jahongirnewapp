<?php

namespace App\Filament\Resources;

use Filament\Forms;
use Filament\Tables;
use App\Models\Guide;
use App\Models\Driver;
use Filament\Forms\Form;
use Filament\Tables\Table;
use App\Models\TourExpense;
use Filament\Resources\Resource;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\DatePicker;
use Illuminate\Database\Eloquent\Builder;
use Filament\Forms\Components\MorphToSelect;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\TourExpenseResource\Pages;
use App\Filament\Resources\TourExpenseResource\RelationManagers;

class TourExpenseResource extends Resource
{
    protected static ?string $model = TourExpense::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                MorphToSelect::make('supplier')
                    ->types([
                        MorphToSelect\Type::make(Driver::class)->titleAttribute('full_name'),
                        MorphToSelect\Type::make(Guide::class)->titleAttribute('full_name'),

                    ])
                    ->label('Supplier')
                    ->reactive(), // IMPORTANT

                TextInput::make('amount')->numeric()->required(),
                Textarea::make('description'),
                DatePicker::make('expense_date')->default(now()),
                Select::make('tour_id')
                    ->label('Tour')
                    ->options(function (callable $get) {
                        $supplierType = $get('supplier_type');
                        $supplierId = $get('supplier_id');

                        if (!$supplierType || !$supplierId) {
                            return [];
                        }

                        $supplier = $supplierType::find($supplierId);
                        if (!$supplier) {
                            return [];
                        }

                        // Get unique tours through bookings
                        return $supplier->bookings()
                            ->with('tour')
                            ->get()
                            ->filter(fn($booking) => $booking->tour) // prevent nulls
                            ->mapWithKeys(fn($booking) => [
                                $booking->tour->id => $booking->tour->title
                            ])
                            ->unique()
                            ->toArray();
                    })
                    ->reactive()
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Supplier Type (just class name)
                TextColumn::make('supplier_type')
                    ->label('Supplier Type')
                    ->formatStateUsing(fn(string $state) => class_basename($state))
                    ->sortable(),

                // Supplier Name (handle polymorphic supplier with fallback)
                TextColumn::make('supplier.full_name')
                    ->label('Supplier')
                    ->formatStateUsing(function ($state, $record) {
                        return $record->supplier?->full_name ?? $record->supplier?->full_name ?? '-';
                    })
                    ->sortable()
                    ->searchable(),

                // Tour Title: go through supplier → bookings → tour
                TextColumn::make('tour.title')
                    ->label('Tour')
                    ->sortable()
                    ->searchable(),



                // Amount (format as money)
                TextColumn::make('amount')
                    ->label('Amount')
                    ->formatStateUsing(fn($state) => '$' . number_format($state, 2))
                    ->sortable(),

                // Date
                TextColumn::make('expense_date')
                    ->label('Date')
                    ->date()
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
            'index' => Pages\ListTourExpenses::route('/'),
            'create' => Pages\CreateTourExpense::route('/create'),
            'edit' => Pages\EditTourExpense::route('/{record}/edit'),
        ];
    }
}
