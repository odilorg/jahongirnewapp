<?php

namespace App\Filament\Resources;

use Carbon\Carbon;
use Filament\Forms;
use App\Models\Tour;
use Filament\Tables;
use App\Models\Guest;
use App\Models\Booking;
use Filament\Infolists;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Illuminate\Support\Facades\Log;
use App\Services\OctoPaymentService;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Repeater;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\SelectColumn;
use Illuminate\Database\Eloquent\Builder;
use Filament\Infolists\Components\TextEntry;

// Import the Notification builder
use App\Filament\Resources\BookingResource\Pages;

// Import your Octo payment service
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\BookingResource\RelationManagers;
use App\Filament\Resources\BookingResource\RelationManagers\DriverRelationManager;
use App\Filament\Resources\BookingResource\RelationManagers\DriversRelationManager;
use App\Filament\Resources\BookingResource\RelationManagers\TourExpensesRelationManager;

class BookingResource extends Resource
{
    protected static ?string $model = Booking::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?string $navigationGroup = 'Tour Details';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Group::make()
                    ->schema([
                        Section::make('Booking Information')
                            ->schema([
                                Forms\Components\Select::make('guest_id')
                                    ->required()
                                    ->searchable()
                                    ->createOptionForm([
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
                                        Forms\Components\TextInput::make('phone')
                                            ->tel()
                                            ->required()
                                            ->maxLength(255),
                                        Forms\Components\TextInput::make('country')
                                            ->required()
                                            ->maxLength(255),
                                        Forms\Components\TextInput::make('number_of_people')
                                            ->required()
                                            ->numeric(),
                                    ])
                                    ->preload()
                                    ->relationship('guest', 'full_name')
                                    ->reactive()
                                    ->afterStateUpdated(function (callable $set, callable $get, $state) {
                                        $guest = Guest::find($state);
                                        if ($guest) {
                                            $set('group_name', $guest->full_name);
                                        }
                                    }),

                                Forms\Components\Hidden::make('booking_number')
                                    ->disabled()
                                    ->dehydrated(fn($get) => filled($get('booking_number'))) // only save if filled
                                    ->required(fn($context) => $context === 'edit'), // required only on edit

                                Forms\Components\DateTimePicker::make('booking_start_date_time')
                                    ->label('Tour Start Date & Time')
                                    ->required()
                                    ->native(false),

                                Forms\Components\Select::make('guide_id')
                                    ->searchable()
                                    ->preload()
                                    ->relationship('guide', 'full_name'),

                                Forms\Components\Select::make('tour_id')
                                    ->required()->searchable()->preload()
                                    ->relationship('tour', 'title')
                                    ->reactive()
                                    ->afterStateUpdated(function (callable $set, callable $get, $state) {
                                        /*  Recalculate the end date whenever the tour changes           */
                                        $start = $get('booking_start_date_time');
                                        if ($start && $state) {
                                            if ($tour = Tour::find($state)) {
                                                $duration = max(1, (int) $tour->tour_duration);
                                                $end      = Carbon::parse($start)->copy()->addDays($duration - 1);
                                                $set('booking_end_date_time', $end);
                                            }
                                        }
                                    }),

                                /* ───────────────── NEW: Hidden end-date field ───────────────── */
                                Forms\Components\Hidden::make('booking_end_date_time')
                                    ->dehydrated()          // make sure it is passed to the model
                                    ->required(),           // booking must always have an end date

                                Forms\Components\Select::make('driver_id')
                                    ->searchable()
                                    ->preload()
                                    ->relationship('driver', 'full_name'),

                                Forms\Components\TextInput::make('pickup_location')
                                    ->required()
                                    ->maxLength(255),

                                Forms\Components\TextInput::make('dropoff_location')
                                    ->required()
                                    ->maxLength(255),

                                Radio::make('booking_status')
                                    ->label('Booking Status')
                                    ->options([
                                        'pending' => 'Pending',
                                        'in_progress' => 'in Progress',
                                        'finished' => 'Finished',
                                    ]),

                                Radio::make('booking_source')
                                    ->label('Booking Source')
                                    ->options([
                                        'viatour' => 'Viatour',
                                        'geturguide' => 'GetUrGuide',
                                        'website' => 'Website',
                                        'walkin' => 'Walk In',
                                        'phone' => 'Phone',
                                        'email' => 'Email',
                                        'other' => 'Other',
                                    ])
                                    ->columns(2),

                                Forms\Components\Textarea::make('special_requests')
                                    ->maxLength(65535),
                            ])
                            ->columns(2),
                    ])
                    ->columnSpan(2),
            ])
            ->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('guest.full_name')
                    ->searchable()
                    ->label('Guest Name'),
                SelectColumn::make('booking_status')
                    ->options([
                        'pending' => 'Pending',
                        'in_progress' => 'in Progress',
                        'finished' => 'Finished',
                    ]),
                Tables\Columns\TextColumn::make('booking_number')
                    ->label('Booking #')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('tour.title')
                    ->searchable()
                    ->limit(20),
                Tables\Columns\TextColumn::make('booking_source')
                    ->label('Source')
                    ->searchable(),
                Tables\Columns\TextColumn::make('booking_start_date_time')
                    ->label('Start DT')
                    ->dateTime('M d, Y H:i')
                    ->sortable(),

                // Display the *latest* guest payment status (if any)
                Tables\Columns\TextColumn::make('guestPayments.payment_status')
                    ->label('Payment Status'),

                Tables\Columns\TextColumn::make('guestPayments.payment_method')
                    ->label('Payment Method'),

                Tables\Columns\TextColumn::make('guestPayments.amount')
                    ->label('Amount Paid'),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('pickup_location')
                    ->label('Pickup')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('dropoff_location')
                    ->label('Dropoff')
                    ->searchable(),

                Tables\Columns\TextColumn::make('special_requests')
                    ->label('Note')
                    ->searchable()
                    ->limit(20),
            ])
            ->defaultSort('booking_start_date_time', 'asc')
            ->filters([])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('download_pdf')
                    ->label('Download PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->visible(fn(Booking $record): bool => !is_null($record->file_name))
                    ->action(function (Booking $record) {
                        return response()->download(storage_path('app/public/confirmations/') . $record->file_name);
                    }),

                Action::make('createPaymentLink')
                    ->label('Create Payment Link')
                    ->action(function (Booking $record) {
                        // Determine payment amount from Booking->amount or GuestPayment
                        $paymentAmount = $record->amount;
                        if (!$paymentAmount) {
                            $guestPayment = $record->guestPayments()->first();
                            if ($guestPayment && $guestPayment->amount) {
                                $paymentAmount = $guestPayment->amount;
                            }
                        }

                        if (!$paymentAmount) {
                            Notification::make()
                                ->title('No amount set for this booking!')
                                ->danger()
                                ->send();
                            return;
                        }

                        $service = new OctoPaymentService();

                        try {
                            $paymentUrl = $service->createPaymentLink($record, $paymentAmount);
                            $record->payment_link = $paymentUrl;
                            $record->save();

                            Notification::make()
                                ->title('Payment link created!')
                                ->success()
                                ->body('Link: ' . $paymentUrl)
                                ->send();
                        } catch (\Exception $ex) {
                            Notification::make()
                                ->title('Failed to create payment link.')
                                ->danger()
                                ->body($ex->getMessage())
                                ->send();
                        }
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-banknotes'),
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
                Infolists\Components\Section::make('Booking details')
                    ->schema([
                        Infolists\Components\TextEntry::make('tour.title'),
                        Infolists\Components\TextEntry::make('booking_start_date_time'),
                        Infolists\Components\TextEntry::make('pickup_location'),
                        TextEntry::make('dropoff_location'),
                        TextEntry::make('special_requests'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            // Define your relation managers here if needed
            TourExpensesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListBookings::route('/'),
            'create' => Pages\CreateBooking::route('/create'),
            'edit'   => Pages\EditBooking::route('/{record}/edit'),
            // 'view' => Pages\ViewBooking::route('/{record}'),
        ];
    }
}
