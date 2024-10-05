<?php

namespace App\Filament\Resources;

use Filament\Forms;
use Filament\Tables;
use App\Models\Guide;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ImageEntry;
use App\Filament\Resources\GuideResource\Pages;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\GuideResource\RelationManagers;
use App\Filament\Resources\GuideResource\RelationManagers\LanguagesRelationManager;
use App\Filament\Resources\GuideResource\RelationManagers\SupplierPaymentsRelationManager;

class GuideResource extends Resource
{
    protected static ?string $model = Guide::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-currency-bangladeshi';

    protected static ?string $navigationGroup = 'Driver and Guide Details';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Guide Personal Info')
                    ->description('Add information about Guide')
                    ->collapsible()
                    ->schema([
                        Forms\Components\TextInput::make('first_name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('last_name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('email')
                            ->email()
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('phone01')
                            ->tel()
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('phone02')
                            ->tel()
                            ->maxLength(255),
                        Forms\Components\Select::make('languages')
                            ->relationship('languages', 'language')
                            ->createOptionForm([
                                Forms\Components\TextInput::make('language')
                    ->required()
                    ->maxLength(255),
                            ])
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->required(),
                        Forms\Components\FileUpload::make('guide_image')
                            ->image()
                           // ->required(),
                    ])->columns(2)



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
                Tables\Columns\TextColumn::make('first_name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('last_name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable(),
                Tables\Columns\TextColumn::make('phone01')
                    ->searchable(),
                Tables\Columns\TextColumn::make('phone02')
                    ->searchable(),
                Tables\Columns\TextColumn::make('languages.language')
                    ->listWithLineBreaks()
                    ->searchable(),
                Tables\Columns\ImageColumn::make('guide_image'),
                Tables\Columns\TextColumn::make('full_name')
                    ->searchable(),
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
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist


            ->schema([

                Section::make('Guide Info')
                    // ->description('Prevent abuse by limiting the number of requests per period')
                    ->schema([
                        TextEntry::make('full_name'),
                        TextEntry::make('email'),
                        TextEntry::make('phone01'),
                        TextEntry::make('phone02'),
                        TextEntry::make('languages.language'),
                        ImageEntry::make('guide_image')
                    ])->columns(2)


            ]);
    }

    public static function getRelations(): array
    {
        return [
            LanguagesRelationManager::class,
           // SupplierPaymentsRelationManager::class
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGuides::route('/'),
            'create' => Pages\CreateGuide::route('/create'),
            'edit' => Pages\EditGuide::route('/{record}/edit'),
        ];
    }
}
