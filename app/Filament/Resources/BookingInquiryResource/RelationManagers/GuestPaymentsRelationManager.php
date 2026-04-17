<?php

declare(strict_types=1);

namespace App\Filament\Resources\BookingInquiryResource\RelationManagers;

use App\Models\GuestPayment;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class GuestPaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'guestPayments';

    protected static ?string $title = 'Guest Payments';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('amount')
                ->prefix('$')
                ->required()
                ->numeric()
                ->step('0.01')
                ->helperText('Positive = received, negative = refund'),

            Forms\Components\Select::make('payment_method')
                ->options(GuestPayment::METHODS)
                ->required()
                ->native(false)
                ->default('cash'),

            Forms\Components\Select::make('payment_type')
                ->options(GuestPayment::TYPES)
                ->required()
                ->native(false)
                ->default('balance'),

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
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        $owner    = $this->getOwnerRecord();
        $quoted   = (float) ($owner->price_quoted ?? 0);
        $received = $owner->totalReceived();
        $outstanding = $quoted - $received;

        return $table
            ->description(sprintf(
                'Quoted: $%s · Received: $%s · Outstanding: $%s',
                number_format($quoted, 2),
                number_format($received, 2),
                number_format($outstanding, 2),
            ))
            ->defaultSort('payment_date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('payment_date')
                    ->date('M j, Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('amount')
                    ->money('USD')
                    ->color(fn ($record) => $record->amount < 0 ? 'danger' : 'success'),
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
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => $state === 'voided' ? 'danger' : 'success'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['recorded_by_user_id'] = auth()->id();

                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }
}
