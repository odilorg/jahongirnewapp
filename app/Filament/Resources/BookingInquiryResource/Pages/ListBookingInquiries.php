<?php

declare(strict_types=1);

namespace App\Filament\Resources\BookingInquiryResource\Pages;

use App\Filament\Resources\BookingInquiryResource;
use App\Models\BookingInquiry;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListBookingInquiries extends ListRecords
{
    protected static string $resource = BookingInquiryResource::class;

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

            'confirmed' => Tab::make('Confirmed')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', BookingInquiry::STATUS_CONFIRMED))
                ->badge(fn () => BookingInquiry::where('status', BookingInquiry::STATUS_CONFIRMED)->count())
                ->badgeColor('success'),

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
