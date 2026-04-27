<?php

namespace App\Filament\Resources;

use App\Models\Meter;
use App\Models\UtilityUsage;
use App\Services\Meters\MeterReadingChainService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use App\Filament\Resources\UtilityUsageResource\Pages;

class UtilityUsageResource extends Resource
{
    protected static ?string $model = UtilityUsage::class;
    protected static ?string $navigationGroup = 'Hotel Management';
    protected static ?string $navigationParentItem = 'Коммунальные услуги';
    protected static ?string $navigationLabel = 'Показания';
    protected static ?string $modelLabel = 'Показания';
    protected static ?string $pluralModelLabel = 'Показания';

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static string $view = 'filament.resources.utility-usages.pages.view-utility-usage.blade';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('hotel_id')
                    ->relationship('hotel', 'name')
                    ->required()
                    ->reactive(),
                Forms\Components\Select::make('utility_id')
                    ->relationship('utility', 'name')
                    ->required()
                    ->reactive(),
                Forms\Components\Select::make('meter_id')
                    ->label('Meter')
                    ->required()
                    ->options(function (callable $get) {
                        $hotelId = $get('hotel_id');
                        $utilityId = $get('utility_id');

                        if ($hotelId && $utilityId) {
                            return Meter::where('hotel_id', $hotelId)
                                ->where('utility_id', $utilityId)
                                ->pluck('meter_serial_number', 'id');
                        }

                        return [];
                    })
                    ->reactive()
                    // When the operator picks a meter, auto-fill the
                    // previous reading from the latest existing reading
                    // on that meter (or 0 for first-ever).
                    ->afterStateUpdated(function ($state, Set $set, Get $get) {
                        if (! $state) {
                            return;
                        }
                        // Don't overwrite an override the operator
                        // already toggled on this form.
                        if ($get('meter_previous_overridden')) {
                            return;
                        }

                        $set(
                            'meter_previous',
                            app(MeterReadingChainService::class)->autoFillPrevious((int) $state),
                        );
                        self::recalculateDifference($get, $set);
                    }),
                Forms\Components\DatePicker::make('usage_date')
                    ->default(now())
                    ->required()
                    // Block accidental backdating: the picker can't pick
                    // dates on or before the latest existing reading.
                    // Backend MeterReadingChainService re-validates.
                    ->minDate(function (Get $get) {
                        $meterId = $get('meter_id');
                        if (! $meterId) {
                            return null;
                        }
                        $last = app(MeterReadingChainService::class)->lastReadingFor((int) $meterId);

                        return $last ? \Illuminate\Support\Carbon::parse($last->usage_date)->addDay() : null;
                    }),

                // ── Previous-reading subgroup ────────────────────
                // The operator should never need to type meter_previous
                // by hand in the normal case. The toggle below makes
                // the rare override explicit and auditable.
                Forms\Components\Toggle::make('meter_previous_overridden')
                    ->label('Изменить предыдущее значение вручную')
                    ->helperText('Используйте, только если автозаполнение неверно (замена счётчика, пропущенный месяц, ошибка ввода ранее).')
                    ->default(false)
                    ->reactive()
                    ->afterStateUpdated(function ($state, Set $set, Get $get) {
                        // Toggling OFF re-locks the field to auto-fill.
                        if (! $state) {
                            $meterId = $get('meter_id');
                            if ($meterId) {
                                $set('meter_previous', app(MeterReadingChainService::class)->autoFillPrevious((int) $meterId));
                            }
                            $set('meter_previous_override_reason', null);
                            self::recalculateDifference($get, $set);
                        }
                    }),
                Forms\Components\TextInput::make('meter_previous')
                    ->label('Предыдущее показание')
                    ->required()
                    ->numeric()
                    ->live(onBlur: true)
                    ->disabled(fn (Get $get) => ! (bool) $get('meter_previous_overridden'))
                    ->dehydrated() // disabled fields aren't dehydrated by default
                    ->afterStateUpdated(function (Get $get, Set $set) {
                        self::recalculateDifference($get, $set);
                    }),
                Forms\Components\Textarea::make('meter_previous_override_reason')
                    ->label('Причина изменения')
                    ->rows(2)
                    ->maxLength(500)
                    ->visible(fn (Get $get) => (bool) $get('meter_previous_overridden'))
                    ->required(fn (Get $get) => (bool) $get('meter_previous_overridden')),

                // ── Latest reading + reset toggle ────────────────
                Forms\Components\TextInput::make('meter_latest')
                    ->label('Текущее показание')
                    ->required()
                    ->numeric()
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Get $get, Set $set) {
                        self::recalculateDifference($get, $set);
                    }),
                Forms\Components\Toggle::make('is_meter_reset')
                    ->label('Сброс / замена счётчика')
                    ->helperText('Отметьте, если счётчик был сброшен или заменён и текущее значение начинается заново.')
                    ->default(false),

                Forms\Components\TextInput::make('meter_difference')
                    ->label('Разница')
                    ->required()
                    ->numeric()
                    ->readOnly(),
                Forms\Components\FileUpload::make('meter_image')
                    ->image(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('hotel.name')->sortable(),
                Tables\Columns\TextColumn::make('utility.name')->sortable(),
                Tables\Columns\TextColumn::make('meter.meter_serial_number')->sortable(),
                Tables\Columns\TextColumn::make('usage_date')->date()->sortable(),
                Tables\Columns\TextColumn::make('meter_latest')->numeric()->sortable(),
                Tables\Columns\TextColumn::make('meter_previous')->numeric()->sortable(),
                Tables\Columns\TextColumn::make('meter_difference')->numeric()->sortable(),
                Tables\Columns\IconColumn::make('is_meter_reset')->boolean()->label('Сброс')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('meter_previous_overridden')->boolean()->label('Override')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListUtilityUsages::route('/'),
            'create' => Pages\CreateUtilityUsage::route('/create'),
            'edit'   => Pages\EditUtilityUsage::route('/{record}/edit'),
            'view'   => Pages\ViewUtilityUsage::route('/{record}'),
            'print'  => Pages\PrintUtilityUsage::route('/{record}/print'),
        ];
    }

    /**
     * Recompute meter_difference whenever an input that feeds it
     * changes. The model's saving guard recomputes again as the
     * source of truth — this is just live UI feedback.
     */
    public static function recalculateDifference(Get $get, Set $set): void
    {
        $latest = (int) ($get('meter_latest') ?? 0);
        $previous = (int) ($get('meter_previous') ?? 0);
        $set('meter_difference', $latest - $previous);
    }
}
