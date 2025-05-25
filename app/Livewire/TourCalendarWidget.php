<?php

namespace App\Livewire;

use Carbon\Carbon;
use Livewire\Livewire;
use App\Models\Booking;
use Livewire\Component;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class TourCalendarWidget extends Component
{
    /* ───── Calendar range ───── */
    public Carbon $startDate;
    public Carbon $endDate;
    public Collection $days;

    /* ───── Modal state ───── */
    public bool $showModal = false;
    public ?Booking $selected = null;

    /* ───── Mount with month (YYYY-MM) ───── */
    public function mount(string $month = '2025-05'): void
    {
        $monthObj        = Carbon::parse($month . '-01');
        $this->startDate = $monthObj->copy()->startOfMonth();
        $this->endDate   = $monthObj->copy()->endOfMonth();

        $this->days = collect();
        for ($d = $this->startDate->copy(); $d->lte($this->endDate); $d->addDay()) {
            $this->days->push($d->copy());
        }
    }

    

    /* ───── Called from wire:click="showBooking(id)" ───── */
    public function showBooking(int $id): void
    {
           

        $this->selected  = Booking::with(['tour', 'guest', 'driver', 'guide'])->find($id);
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
    }

    /* ───── Build rows & render ───── */
    public function render()
    {
        $windowStart = $this->startDate->copy()->startOfDay();
        $windowEnd   = $this->endDate  ->copy()->endOfDay();
        $daysCount   = $this->days->count();

        $bookings = Booking::with(['tour:id,title', 'guest:id,full_name'])
            ->whereDate('booking_start_date_time', '<=', $windowEnd)
            ->where(function ($q) use ($windowStart) {
                $q->whereNull('booking_end_date_time')
                  ->orWhereDate('booking_end_date_time', '>=', $windowStart);
            })
            ->get()
            ->map(function ($b) use ($windowStart, $windowEnd, $daysCount) {
                $start = Carbon::parse($b->booking_start_date_time)->startOfDay();
                $end   = $b->booking_end_date_time
                         ? Carbon::parse($b->booking_end_date_time)->startOfDay()
                         : $start;

                /* clip long events */
                $start = $start->lt($windowStart) ? $windowStart->copy() : $start;
                $end   = $end  ->gt($windowEnd)   ? $windowEnd  ->copy() : $end;

                $b->start_index = $windowStart->diffInDays($start);
                $b->span_days   = max(1, $start->diffInDays($end) + 1);

                $b->bar_label   = $b->guest?->full_name
                                 ?? $b->group_name
                                 ?? "Booking #{$b->id}";

                $b->colour      = $b->payment_status === 'paid'
                                  ? '#16a34a'  // green
                                  : '#f97316'; // orange
                return $b;
            });

        $rows = $bookings->groupBy(
            fn ($b) => $b->tour?->title ?? 'Unknown Tour'
        );

        return view('livewire.tour-calendar-widget', [
            'startDate' => $this->startDate,
            'days'      => $this->days,
            'rows'      => $rows,
            'showModal' => $this->showModal,
            'selected'  => $this->selected,
        ]);
    }
}
