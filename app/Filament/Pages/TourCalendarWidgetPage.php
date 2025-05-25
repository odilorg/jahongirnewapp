<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class TourCalendarWidgetPage extends Page
{
    protected static string $view = 'filament.pages.tour-calendar-widget-page';
    public static ?string $navigationIcon = 'heroicon-o-calendar';
    public static ?string $navigationGroup = 'Tours';
}
