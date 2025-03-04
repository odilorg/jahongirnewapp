<?php

namespace App\Filament\Resources;

use Filament\Forms;
use Filament\Tables;
use App\Models\Booking;
use Filament\Forms\Form;
use Filament\Tables\Table;
use App\Models\GuestPayment;
use Filament\Resources\Resource;
use Filament\Tables\Columns\SelectColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\GuestPaymentResource\Pages;
use App\Filament\Resources\GuestPaymentResource\RelationManagers;

class GuestPaymentResource extends Resource
{
    protected static ?string $model = GuestPayment::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?string $navigationGroup = 'Tour Details';


    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('guest_id')
                ->label('Guest')
                ->relationship('guest', 'full_name')
                ->reactive(), // <-- important to trigger changes

            Forms\Components\Select::make('booking_id')
                ->label('Tour')
                ->options(function (callable $get) {
                    $guestId = $get('guest_id');

                    // If no guest selected yet, return empty array
                    if (!$guestId) {
                        return [];
                    }

                    // Retrieve all bookings for this guest,
                    // pluck the Tour name as label, and the booking ID as the key
                    return Booking::where('guest_id', $guestId)
                        ->get()
                        ->pluck('tour.title', 'id');
                })
                ->required(),
                Forms\Components\TextInput::make('amount')
                ->numeric()
                ->prefix('$')
                ->maxValue(10000)
                ->minValue(0), 
                Forms\Components\DatePicker::make('payment_date')
                    ->native(false)
                    ->required(),
                    Forms\Components\Select::make('payment_method')
                    ->required()
                    ->options([
                        'cash' => 'Cash',
                        'card' => 'Card',
                        'paypal' => 'PayPal',
                        'bank' => 'Bank Transfer',
                        'stripe' => 'Stripe',
                    ])
                    ->default('not paid')  // Set the default option to 'Not Paid'
                    ->label('Payment Method'),
                    Forms\Components\Select::make('payment_status')
                    ->required()
                    ->options([
                        'paid' => 'Paid',
                        'not_paid' => 'Not Paid',
                        'partially_paid' => 'Partially Paid',
                    ])
                    ->default('not paid')  // Set the default option to 'Not Paid'
                    ->label('Payment Status'),
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
                Tables\Columns\TextColumn::make('guest.full_name')
                    ->sortable(),
                Tables\Columns\TextColumn::make('booking.tour.title')
                    ->sortable(),
                Tables\Columns\TextColumn::make('amount')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('payment_date')
                    ->date()
                    ->sortable(),
                    SelectColumn::make('payment_method')
                    ->options([
                        'cash' => 'Cash',
                        'card' => 'Card',
                        'paypal' => 'PayPal',
                        'bank' => 'Bank Transfer',
                        'stripe' => 'Stripe',
                    ]),
                    SelectColumn::make('payment_status')
                    ->options([
                        'paid' => 'Paid',
                        'not_paid' => 'Not Paid',
                        'partially_paid' => 'Partially Paid',
                    ]),
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
            'index' => Pages\ListGuestPayments::route('/'),
            'create' => Pages\CreateGuestPayment::route('/create'),
            'edit' => Pages\EditGuestPayment::route('/{record}/edit'),
        ];
    }
}
