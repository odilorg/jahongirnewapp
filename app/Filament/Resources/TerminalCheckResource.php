<?php

namespace App\Filament\Resources;



use Illuminate\Foundation\Auth\User as Authenticatable;
use Filament\Forms;
use Filament\Tables;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Support\RawJs;
use App\Models\TerminalCheck;
use Illuminate\Support\Carbon;
use Filament\Resources\Resource;
use Filament\Actions\CreateAction;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Filament\Tables\Columns\Summarizers\Sum;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\TerminalCheckResource\Pages;
use App\Filament\Resources\TerminalCheckResource\RelationManagers;

class TerminalCheckResource extends Resource
{
    protected static ?string $model = TerminalCheck::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'Accounting';

    
    

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
                ]),
            Forms\Components\Select::make('doc_type')
                ->options([
                    'TerminalCheck' => 'TerminalCheck',
                    'BankVipiska' => 'BankVipiska',
                    
                ])    
                
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
        ->groups([
            Group::make('created_at')
                ->date(),
        ])
        //->dateTime()
        ->defaultGroup('check_date')
            ->columns([

                
                Tables\Columns\TextColumn::make('check_date')
                // ->defaultGroup('check_date')
                ->sortable()
                ->dateTime(),
                Tables\Columns\TextColumn::make('card_type')
                ->badge()
                ->color(fn (string $state): string => match ($state) {
                    'Humo OK' => 'info',
                    'Humo YTT' => 'warning',
                    'Uzcard OK' => 'success',
                    'Uzcard YTT' => 'danger',
                }),
            Tables\Columns\TextColumn::make('doc_type')
                ->badge()
                ->color(fn (string $state): string => match ($state) {
                    'TerminalCheck' => 'info',
                    'BankVipiska' => 'warning',
                   
                }),
                Tables\Columns\TextColumn::make('amount')
                ->summarize(Sum::make()->money('UZS', divideBy: 100))
              ->money('UZS')

            ])
           
            ->filters([
                SelectFilter::make('card_type')
                ->options([
                    'Humo OK' => 'Humo OK',
                    'Humo YTT' => 'Humo YTT',
                    'Uzcard OK' => 'Uzcard OK',
                    'Uzcard YTT' => 'Uzcard YTT',
                ]),
                SelectFilter::make('doc_type')
                ->options([
                   'TerminalCheck' => 'TerminalCheck',
                    'BankVipiska' => 'BankVipiska',
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
    })->columnSpan(3)->columns(2)


                ], layout: FiltersLayout::AboveContent)
                ->filtersFormColumns(2) 
            
                



            ->actions([
                Tables\Actions\EditAction::make()
                //->successRedirectUrl(route('terminal-checks')),
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

    protected function getHeaderActions(): array {
        return [
            CreateAction::make()
        ];
    }

    public static function canViewAny(): bool
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();
        
        return $user && $user->hasRole('super_admin');
    }

}
