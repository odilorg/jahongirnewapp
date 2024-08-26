<?php

namespace App\Filament\Resources;

use Filament\Forms;
use Filament\Tables;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Support\RawJs;
use App\Models\TerminalCheck;
use Illuminate\Support\Carbon;
use Filament\Resources\Resource;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\TerminalCheckResource\Pages;
use App\Filament\Resources\TerminalCheckResource\RelationManagers;

class TerminalCheckResource extends Resource
{
    protected static ?string $model = TerminalCheck::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
            Forms\Components\TextInput::make('amount')
            ->prefix('UZS')
            ->mask(RawJs::make('$money($input)'))
            ->stripCharacters(',')
            ->numeric(),
           
                
            Forms\Components\DatePicker::make('check_date')
                ->required()
                ->native(false)
                ->maxDate(now()),
            Forms\Components\Select::make('card_type')
                ->options([
                    'Humo OK' => 'Humo OK',
                    'Humo YTT' => 'Humo YTT',
                    'Uzcard OK' => 'Uzcard OK',
                    'Uzcard YTT' => 'Uzcard YTT',
                ])
                
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('check_date')
                ->sortable(),
                Tables\Columns\TextColumn::make('card_type')
                ->badge()
                ->color(fn (string $state): string => match ($state) {
                    'Humo OK' => 'info',
                    'Humo YTT' => 'warning',
                    'Uzcard OK' => 'success',
                    'Uzcard YTT' => 'danger',
                }),
                Tables\Columns\TextColumn::make('amount'),

            ])
            ->filters([
                SelectFilter::make('card_type')
                ->options([
                    'Humo OK' => 'Humo OK',
                    'Humo YTT' => 'Humo YTT',
                    'Uzcard OK' => 'Uzcard OK',
                    'Uzcard YTT' => 'Uzcard YTT',
                ]),

                Filter::make('check_date')
    ->form([
        Forms\Components\DatePicker::make('created_from'),
        Forms\Components\DatePicker::make('created_until'),
    ])
    ->query(function (Builder $query, array $data): Builder {
        return $query
            ->when(
                $data['created_from'],
                fn (Builder $query, $date): Builder => $query->whereDate('check_date', '>=', $date),
            )
            ->when(
                $data['created_until'],
                fn (Builder $query, $date): Builder => $query->whereDate('check_date', '<=', $date),
            );

            
    })
    ->indicateUsing(function (array $data): array {
        $indicators = [];
 
        if ($data['created_from'] ?? null) {
            $indicators['created_from'] = 'Created from ' . Carbon::parse($data['created_from'])->toFormattedDateString();
        }
 
        if ($data['created_until'] ?? null) {
            $indicators['created_until'] = 'Created until ' . Carbon::parse($data['created_until'])->toFormattedDateString();
        }
 
        return $indicators;
    })->columnSpan(2)->columns(2)


                ], layout: FiltersLayout::AboveContent)
                ->filtersFormColumns(3) 
            
                



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
            'index' => Pages\ListTerminalChecks::route('/'),
            'create' => Pages\CreateTerminalCheck::route('/create'),
            'edit' => Pages\EditTerminalCheck::route('/{record}/edit'),
        ];
    }
}
