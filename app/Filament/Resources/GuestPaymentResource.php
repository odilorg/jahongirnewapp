<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\GuestPaymentResource\Pages;
use App\Models\BookingInquiry;
use App\Models\GuestPayment;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class GuestPaymentResource extends Resource
{
    protected static ?string $model = GuestPayment::class;

    protected static ?string $navigationIcon  = 'heroicon-o-credit-card';
    protected static ?string $navigationLabel = 'Guest Payments';
    protected static ?string $navigationGroup = 'Tour Operations';
    protected static ?int    $navigationSort  = 30;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Payment details')
                ->schema([
                    Forms\Components\Select::make('booking_inquiry_id')
                        ->label('Booking')
                        ->options(fn () => BookingInquiry::query()
                            ->orderByDesc('travel_date')
                            ->limit(500)
                            ->get()
                            ->mapWithKeys(fn ($i) => [
                                $i->id => "{$i->reference} · {$i->customer_name} · {$i->travel_date?->format('M j')}",
                            ])
                            ->all())
                        ->required()
                        ->searchable(),

                    Forms\Components\TextInput::make('amount')
                        ->prefix('$')
                        ->required()
                        ->numeric()
                        ->step('0.01')
                        ->helperText('Positive = received, negative = refund'),

                    Forms\Components\Select::make('payment_type')
                        ->options(GuestPayment::TYPES)
                        ->required()
                        ->native(false)
                        ->default('full'),

                    Forms\Components\Select::make('payment_method')
                        ->options(GuestPayment::METHODS)
                        ->required()
                        ->native(false)
                        ->default('cash'),

                    Forms\Components\DatePicker::make('payment_date')
                        ->required()
                        ->default(now()),

                    Forms\Components\TextInput::make('reference')
                        ->placeholder('Octo txn / receipt / bank ref')
                        ->maxLength(100),

                    Forms\Components\Textarea::make('notes')
                        ->rows(2)
                        ->columnSpanFull(),

                    Forms\Components\FileUpload::make('receipt_path')
                        ->label('Receipt')
                        ->directory('guest-receipts')
                        ->acceptedFileTypes(['image/*', 'application/pdf'])
                        ->maxSize(5120),

                    Forms\Components\Select::make('status')
                        ->options(GuestPayment::STATUSES)
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
                Tables\Columns\TextColumn::make('bookingInquiry.reference')
                    ->label('Booking')
                    ->searchable()
                    ->url(fn ($record) => $record->booking_inquiry_id
                        ? BookingInquiryResource::getUrl('view', ['record' => $record->booking_inquiry_id])
                        : null),
                Tables\Columns\TextColumn::make('bookingInquiry.customer_name')
                    ->label('Guest')
                    ->searchable(),
                Tables\Columns\TextColumn::make('amount')
                    ->money('USD')
                    ->color(fn ($record) => $record->amount < 0 ? 'danger' : 'success')
                    ->sortable(),
                Tables\Columns\TextColumn::make('payment_method')
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('payment_type')
                    ->badge()
                    ->color('primary'),
                Tables\Columns\TextColumn::make('reference')
                    ->placeholder('—')
                    ->limit(20),
                Tables\Columns\TextColumn::make('recordedByUser.name')
                    ->label('By')
                    ->placeholder('System'),
                Tables\Columns\IconColumn::make('receipt_path')
                    ->label('Receipt')
                    ->boolean()
                    ->trueIcon('heroicon-o-paper-clip')
                    ->falseIcon('heroicon-o-minus'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => $state === 'voided' ? 'danger' : 'success'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('payment_method')
                    ->options(GuestPayment::METHODS),
                Tables\Filters\SelectFilter::make('payment_type')
                    ->options(GuestPayment::TYPES),
                Tables\Filters\SelectFilter::make('status')
                    ->options(GuestPayment::STATUSES),
                Tables\Filters\Filter::make('has_receipt')
                    ->label('With receipt')
                    ->query(fn (Builder $q) => $q->whereNotNull('receipt_path')),
                Tables\Filters\Filter::make('refunds')
                    ->label('Refunds only')
                    ->query(fn (Builder $q) => $q->where('amount', '<', 0)),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('void')
                    ->label('Void')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn ($record) => $record->status === 'recorded')
                    ->action(fn ($record) => $record->update(['status' => 'voided'])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListGuestPayments::route('/'),
            'create' => Pages\CreateGuestPayment::route('/create'),
            'edit'   => Pages\EditGuestPayment::route('/{record}/edit'),
        ];
    }
}
