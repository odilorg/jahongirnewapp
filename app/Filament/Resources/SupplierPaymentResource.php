<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\SupplierPaymentResource\Pages;
use App\Models\Accommodation;
use App\Models\Driver;
use App\Models\Guide;
use App\Models\SupplierPayment;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SupplierPaymentResource extends Resource
{
    protected static ?string $model = SupplierPayment::class;

    protected static ?string $navigationIcon  = 'heroicon-o-banknotes';
    protected static ?string $navigationLabel = 'Supplier Payments';
    protected static ?string $navigationGroup = 'Finance';
    protected static ?int    $navigationSort  = 10;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Payment details')
                ->schema([
                    Forms\Components\Select::make('supplier_type')
                        ->options(SupplierPayment::TYPES)
                        ->required()
                        ->native(false)
                        ->live(),

                    Forms\Components\Select::make('supplier_id')
                        ->label('Supplier')
                        ->options(function (Forms\Get $get): array {
                            return match ($get('supplier_type')) {
                                'driver'        => Driver::where('is_active', true)->orderBy('first_name')->get()->mapWithKeys(fn ($d) => [$d->id => $d->full_name])->all(),
                                'guide'         => Guide::where('is_active', true)->orderBy('first_name')->get()->mapWithKeys(fn ($g) => [$g->id => $g->full_name])->all(),
                                'accommodation' => Accommodation::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
                                default         => [],
                            };
                        })
                        ->required()
                        ->searchable()
                        ->visible(fn (Forms\Get $get): bool => filled($get('supplier_type'))),

                    Forms\Components\Select::make('booking_inquiry_id')
                        ->label('Booking (optional)')
                        ->relationship('bookingInquiry', 'reference')
                        ->searchable()
                        ->preload()
                        ->placeholder('General / advance payment'),

                    Forms\Components\TextInput::make('amount')
                        ->prefix('$')
                        ->required()
                        ->numeric()
                        ->step('0.01'),

                    Forms\Components\DatePicker::make('payment_date')
                        ->required()
                        ->default(now()),

                    Forms\Components\Select::make('payment_method')
                        ->options(SupplierPayment::METHODS)
                        ->required()
                        ->native(false)
                        ->default('cash'),

                    Forms\Components\TextInput::make('reference')
                        ->placeholder('Transfer ref, receipt #')
                        ->maxLength(100),

                    Forms\Components\Textarea::make('notes')
                        ->rows(2)
                        ->columnSpanFull(),

                    Forms\Components\FileUpload::make('receipt_path')
                        ->label('Receipt')
                        ->directory('supplier-receipts')
                        ->acceptedFileTypes(['image/*', 'application/pdf'])
                        ->maxSize(5120),

                    Forms\Components\Select::make('status')
                        ->options(SupplierPayment::STATUSES)
                        ->default('recorded')
                        ->native(false),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('payment_date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('payment_date')
                    ->date('M j, Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('supplier_type')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'driver'        => 'primary',
                        'guide'         => 'info',
                        'accommodation' => 'success',
                        default         => 'gray',
                    }),
                Tables\Columns\TextColumn::make('supplier_name')
                    ->label('Supplier')
                    ->searchable(false),
                Tables\Columns\TextColumn::make('bookingInquiry.reference')
                    ->label('Booking')
                    ->placeholder('—')
                    ->url(fn ($record) => $record->booking_inquiry_id
                        ? BookingInquiryResource::getUrl('view', ['record' => $record->booking_inquiry_id])
                        : null),
                Tables\Columns\TextColumn::make('amount')
                    ->money('USD')
                    ->sortable(),
                Tables\Columns\TextColumn::make('payment_method')
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('reference')
                    ->placeholder('—')
                    ->limit(20),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => $state === 'voided' ? 'danger' : 'success'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('supplier_type')
                    ->options(SupplierPayment::TYPES),
                Tables\Filters\SelectFilter::make('payment_method')
                    ->options(SupplierPayment::METHODS),
                Tables\Filters\SelectFilter::make('status')
                    ->options(SupplierPayment::STATUSES),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListSupplierPayments::route('/'),
            'create' => Pages\CreateSupplierPayment::route('/create'),
            'edit'   => Pages\EditSupplierPayment::route('/{record}/edit'),
        ];
    }
}
