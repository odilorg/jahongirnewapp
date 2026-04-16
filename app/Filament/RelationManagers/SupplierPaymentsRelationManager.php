<?php

declare(strict_types=1);

namespace App\Filament\RelationManagers;

use App\Models\SupplierPayment;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared payments relation manager for Driver, Guide, and Accommodation.
 *
 * Each supplier resource registers this with its own supplier_type.
 * The relation is faked via a query scope since SupplierPayment uses
 * supplier_type + supplier_id (not a standard Eloquent relation).
 */
class SupplierPaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'supplierPayments';

    protected static ?string $title = 'Payments';

    // Subclasses set these
    protected string $supplierType = 'driver';

    public static function make(array $properties = []): static
    {
        return parent::make($properties);
    }

    protected function getTableQuery(): Builder
    {
        return SupplierPayment::query()
            ->where('supplier_type', $this->supplierType)
            ->where('supplier_id', $this->getOwnerRecord()->getKey());
    }

    protected function canCreate(): bool
    {
        return true;
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Hidden::make('supplier_type')
                ->default($this->supplierType),
            Forms\Components\Hidden::make('supplier_id')
                ->default(fn () => $this->getOwnerRecord()->getKey()),

            Forms\Components\Select::make('booking_inquiry_id')
                ->label('Booking (optional)')
                ->relationship('bookingInquiry', 'reference')
                ->searchable()
                ->preload()
                ->placeholder('General / advance'),

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
        ]);
    }

    public function table(Table $table): Table
    {
        $owner = $this->getOwnerRecord();
        $owed  = method_exists($owner, 'totalOwed') ? $owner->totalOwed() : 0;
        $paid  = method_exists($owner, 'totalPaid') ? $owner->totalPaid() : 0;
        $balance = $owed - $paid;

        return $table
            ->description(sprintf(
                'Owed: $%s · Paid: $%s · Outstanding: $%s',
                number_format($owed, 2),
                number_format($paid, 2),
                number_format($balance, 2),
            ))
            ->defaultSort('payment_date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('payment_date')
                    ->date('M j, Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('bookingInquiry.reference')
                    ->label('Booking')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('amount')
                    ->money('USD'),
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
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['supplier_type'] = $this->supplierType;
                        $data['supplier_id']   = $this->getOwnerRecord()->getKey();

                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }
}
