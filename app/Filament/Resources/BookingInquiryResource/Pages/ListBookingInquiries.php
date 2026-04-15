<?php

declare(strict_types=1);

namespace App\Filament\Resources\BookingInquiryResource\Pages;

use App\Filament\Resources\BookingInquiryResource;
use App\Models\BookingInquiry;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListBookingInquiries extends ListRecords
{
    protected static string $resource = BookingInquiryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('+ New booking inquiry'),
        ];
    }

    /**
     * Status-based tab navigation. "All" is first so operators can see the
     * full picture; "New" is the default working view.
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All')
                ->badge(fn () => BookingInquiry::count()),

            'new' => Tab::make('New')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', BookingInquiry::STATUS_NEW))
                ->badge(fn () => BookingInquiry::where('status', BookingInquiry::STATUS_NEW)->count())
                ->badgeColor('warning'),

            'contacted' => Tab::make('Contacted')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', BookingInquiry::STATUS_CONTACTED))
                ->badge(fn () => BookingInquiry::where('status', BookingInquiry::STATUS_CONTACTED)->count())
                ->badgeColor('info'),

            'awaiting_customer' => Tab::make('Awaiting customer')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', BookingInquiry::STATUS_AWAITING_CUSTOMER))
                ->badge(fn () => BookingInquiry::where('status', BookingInquiry::STATUS_AWAITING_CUSTOMER)->count())
                ->badgeColor('primary'),

            'awaiting_payment' => Tab::make('Awaiting payment')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', BookingInquiry::STATUS_AWAITING_PAYMENT))
                ->badge(fn () => BookingInquiry::where('status', BookingInquiry::STATUS_AWAITING_PAYMENT)->count())
                ->badgeColor('warning'),

            'confirmed' => Tab::make('Confirmed')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', BookingInquiry::STATUS_CONFIRMED))
                ->badge(fn () => BookingInquiry::where('status', BookingInquiry::STATUS_CONFIRMED)->count())
                ->badgeColor('success'),

            // Ops tab: confirmed sales travelling today. This is the
            // dispatcher's working view — who leaves in the next few hours.
            'today' => Tab::make('Today')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('status', BookingInquiry::STATUS_CONFIRMED)
                    ->whereDate('travel_date', today()))
                ->badge(fn () => BookingInquiry::where('status', BookingInquiry::STATUS_CONFIRMED)
                    ->whereDate('travel_date', today())
                    ->count())
                ->badgeColor('success'),

            // Prep view: confirmed sales leaving tomorrow — finalise
            // driver/guide/pickup details today while there is still time.
            'tomorrow' => Tab::make('Tomorrow')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('status', BookingInquiry::STATUS_CONFIRMED)
                    ->whereDate('travel_date', today()->addDay()))
                ->badge(fn () => BookingInquiry::where('status', BookingInquiry::STATUS_CONFIRMED)
                    ->whereDate('travel_date', today()->addDay())
                    ->count())
                ->badgeColor('primary'),

            // Archive: tours that have actually run to completion.
            'completed' => Tab::make('Completed')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('prep_status', BookingInquiry::PREP_COMPLETED))
                ->badge(fn () => BookingInquiry::where('prep_status', BookingInquiry::PREP_COMPLETED)->count()),

            'cancelled' => Tab::make('Cancelled')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', BookingInquiry::STATUS_CANCELLED)),

            'spam' => Tab::make('Spam')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', BookingInquiry::STATUS_SPAM))
                ->badgeColor('danger'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'new';
    }
}
