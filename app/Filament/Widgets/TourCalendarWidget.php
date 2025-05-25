<?php
// app/Filament/Widgets/TourCalendarWidget.php
namespace App\Filament\Widgets;

use Filament\Widgets\Widget;
class TourCalendarWidget extends Widget
{
    protected static string $view = 'filament.widgets.tour-calendar-widget';
    // you can pass public properties just like in your Livewire mount…
    public string $month;
    public function mount(string $month) {
        $this->month = $month;
    }
}
