<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class TourCalendar extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-calendar';
    protected static ?string $navigationGroup = 'Bookings';
    protected static string $view = 'filament.pages.tour-calendar';
}