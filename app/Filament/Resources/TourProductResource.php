<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\TourProductResource\Pages;
use App\Filament\Resources\TourProductResource\RelationManagers;
use App\Models\TourProduct;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/**
 * Tour catalog admin — the public-facing "what we sell" surface.
 *
 * Lives in the Tours nav group, sorted between Tour Calendar and
 * Website Inquiries so operators have:
 *
 *   Tours
 *     ├─ Tour Calendar     (-10 — week dispatch view)
 *     ├─ Tour Products     (-5 — sales catalog)
 *     ├─ Website Inquiries (0)
 *     └─ ... suppliers
 */
class TourProductResource extends Resource
{
    protected static ?string $model = TourProduct::class;

    protected static ?string $navigationIcon  = 'heroicon-o-rectangle-stack';
    protected static ?string $navigationLabel = 'Tour Products';
    protected static ?string $navigationGroup = 'Tours';
    protected static ?int    $navigationSort  = -5;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identity')
                ->schema([
                    Forms\Components\TextInput::make('title')
                        ->required()
                        ->maxLength(255)
                        ->live(debounce: 500)
                        ->afterStateUpdated(function ($state, callable $set, callable $get): void {
                            // Auto-fill slug on first entry only — never
                            // overwrite a slug an operator typed manually
                            // because slug changes break inbound URLs.
                            if (blank($get('slug')) && filled($state)) {
                                $set('slug', Str::slug($state));
                            }
                        }),

                    Forms\Components\TextInput::make('slug')
                        ->required()
                        ->maxLength(191)
                        ->unique(ignoreRecord: true)
                        ->helperText('URL-safe identifier. Set once — changing it later breaks inbound links.'),

                    Forms\Components\Select::make('region')
                        ->options(TourProduct::REGIONS)
                        ->required()
                        ->native(false),

                    Forms\Components\Select::make('tour_type')
                        ->options([
                            TourProduct::TYPE_PRIVATE => 'Private',
                            TourProduct::TYPE_GROUP   => 'Group',
                        ])
                        ->required()
                        ->default(TourProduct::TYPE_PRIVATE)
                        ->native(false),

                    Forms\Components\TextInput::make('duration_days')
                        ->numeric()
                        ->minValue(1)
                        ->default(1)
                        ->required(),

                    Forms\Components\TextInput::make('duration_nights')
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->required(),

                    Forms\Components\Toggle::make('is_active')
                        ->default(true),

                    Forms\Components\TextInput::make('sort_order')
                        ->numeric()
                        ->default(0),
                ])
                ->columns(2),

            Forms\Components\Section::make('Content')
                ->schema([
                    Forms\Components\Textarea::make('description')
                        ->rows(5)
                        ->columnSpanFull(),

                    Forms\Components\TagsInput::make('highlights')
                        ->placeholder('Add highlight…')
                        ->helperText('Press Enter or comma after each bullet. Stored as a JSON list.')
                        ->columnSpanFull(),

                    Forms\Components\Textarea::make('includes')
                        ->rows(3),

                    Forms\Components\Textarea::make('excludes')
                        ->rows(3),
                ])
                ->columns(2)
                ->collapsible(),

            Forms\Components\Section::make('Display & links')
                ->schema([
                    Forms\Components\TextInput::make('hero_image_url')
                        ->label('Hero image URL')
                        ->url()
                        ->maxLength(500),

                    Forms\Components\TextInput::make('page_url')
                        ->label('Website page URL')
                        ->url()
                        ->maxLength(500),

                    Forms\Components\Textarea::make('meta_description')
                        ->rows(2)
                        ->maxLength(500)
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->collapsible()
                ->collapsed(),

            Forms\Components\Section::make('Provenance')
                ->description('Tracks where this tour record came from. Set automatically by importer; rarely edited by hand.')
                ->schema([
                    Forms\Components\Select::make('source_type')
                        ->options([
                            'manual'         => 'Manual',
                            'website_static' => 'Website import',
                            'api'            => 'API',
                        ])
                        ->default('manual')
                        ->native(false),

                    Forms\Components\TextInput::make('source_path')
                        ->maxLength(500)
                        ->placeholder('/domains/jahongir-travel.uz/tours-from-samarkand/foo.php'),
                ])
                ->columns(2)
                ->collapsible()
                ->collapsed(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('region')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => TourProduct::REGIONS[$state] ?? $state)
                    ->sortable(),
                Tables\Columns\TextColumn::make('tour_type')
                    ->badge()
                    ->color(fn (string $state) => $state === TourProduct::TYPE_GROUP ? 'info' : 'gray'),
                Tables\Columns\TextColumn::make('duration_days')
                    ->label('Days')
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('starting_from_usd')
                    ->label('From')
                    ->money('USD')
                    ->placeholder('—')
                    ->sortable(),
                Tables\Columns\TextColumn::make('priceTiers_count')
                    ->label('Tiers')
                    ->counts('priceTiers')
                    ->alignCenter(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('sort_order')
                    ->label('#')
                    ->alignCenter()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('region')
                    ->options(TourProduct::REGIONS)
                    ->multiple(),
                Tables\Filters\SelectFilter::make('tour_type')
                    ->options([
                        TourProduct::TYPE_PRIVATE => 'Private',
                        TourProduct::TYPE_GROUP   => 'Group',
                    ]),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active only')
                    ->default(true),
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
            RelationManagers\DirectionsRelationManager::class,
            RelationManagers\PriceTiersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListTourProducts::route('/'),
            'create' => Pages\CreateTourProduct::route('/create'),
            'edit'   => Pages\EditTourProduct::route('/{record}/edit'),
        ];
    }
}
