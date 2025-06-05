<?php

namespace App\Filament\Pages;

use Filament\Forms;
use Filament\Tables;
use App\Models\Booking;
use Filament\Pages\Page;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Filament\Forms\Components\DatePicker;
use Illuminate\Database\Eloquent\Builder;

class BookingsReport extends Page implements Tables\Contracts\HasTable
{
    use Tables\Concerns\InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';
    protected static ?string $navigationGroup = 'Reports';
    protected static ?string $title = 'Bookings Report';

    // ───────────────────────────────────────────────
    //  DATE RANGE STATE
    // ───────────────────────────────────────────────
    public ?string $startDate = null;
    public ?string $endDate   = null;

    /** ----------------------------------------------------------------
     *  1) Filters form (appears above the table)
     * ----------------------------------------------------------------*/
    protected function getFormSchema(): array
    {
        return [
            DatePicker::make('startDate')
                ->label('Start')
                ->reactive()
                ->default(today()->subMonth())
                ->required(),

            DatePicker::make('endDate')
                ->label('End')
                ->reactive()
                ->default(today())
                ->required(),
        ];
    }

    /** ----------------------------------------------------------------
     *  2) Base query for the table (honours the date filters)
     * ----------------------------------------------------------------*/
    // public function getTableQuery(): Builder
    // {
    //     return Booking::query()
    //         ->when($this->startDate, fn ($q) =>
    //             $q->whereDate('booking_start_date_time', '>=', $this->startDate))
    //         ->when($this->endDate, fn ($q) =>
    //             $q->whereDate('booking_start_date_time', '<=', $this->endDate))
    //         ->with(['tour', 'guest', 'tourExpenses', 'guestPayments']);
    // }

    /** ----------------------------------------------------------------
     *  3) Columns
     * ----------------------------------------------------------------*/
    public function table(Table $table): Table
    {
        return $table
         ->query(fn () =>                     // <── add this block
            Booking::query()
                ->when($this->startDate, fn ($q) =>
                    $q->whereDate('booking_start_date_time', '>=', $this->startDate))
                ->when($this->endDate, fn ($q) =>
                    $q->whereDate('booking_start_date_time', '<=', $this->endDate))
                ->with(['tour', 'guest', 'tourExpenses', 'guestPayments'])
        )                                     // <── end of query()
            ->columns([
                Tables\Columns\TextColumn::make('booking_number')
                    ->label('Booking #')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('booking_start_date_time')
                    ->label('Date')
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('tour.title')
                    ->label('Tour')
                    ->sortable()
                    ->limit(25),

                Tables\Columns\TextColumn::make('guest.full_name')
                    ->label('Guest')
                    ->limit(20),

                /* ──────────  Calculated financials  ────────── */
                Tables\Columns\TextColumn::make('total_payments')
                    ->label('Payments')
                    ->money('usd', true)
                    ->getStateUsing(fn (Booking $record) =>
                        $record->guestPayments()->sum('amount')),

                Tables\Columns\TextColumn::make('total_expenses')
                    ->label('Expenses')
                    ->money('usd', true)
                    ->getStateUsing(fn (Booking $record) =>
                        $record->tourExpenses()->sum('amount')),

                Tables\Columns\TextColumn::make('net_income')
                    ->label('Net')
                    ->money('usd', true)
                    ->getStateUsing(fn (Booking $r) =>
                        $r->guestPayments()->sum('amount') - $r->tourExpenses()->sum('amount'))
                    ->color(fn ($state) => $state < 0 ? 'danger' : 'success'),

                Tables\Columns\TextColumn::make('margin')
                    ->label('Margin %')
                    ->getStateUsing(function (Booking $r) {
                        $payments = $r->guestPayments()->sum('amount');
                        $net = $payments - $r->tourExpenses()->sum('amount');
                        return $payments > 0 ? number_format(($net / $payments) * 100, 2) . '%' : '—';
                    }),
            ])
            ->defaultSort('booking_start_date_time', 'desc');
    }

    /** ----------------------------------------------------------------
     *  4) Blade view = Filament page shell. We don’t need a custom view.
     * ----------------------------------------------------------------*/
protected static string $view = 'filament.pages.bookings-report';
}
