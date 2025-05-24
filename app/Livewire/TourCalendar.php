<?php

namespace App\Livewire;

use Livewire\Component;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use App\Models\Booking;

class TourCalendar extends Component
{
    /** Visible range (inclusive) */
    public Carbon $startDate;
    public Carbon $endDate;

    /** Header cells (Carbon objects) */
    public Collection $days;

    /**
     * optional $month parameter in YYYY-MM format lets you jump to any month,
     * e.g. <livewire:tour-calendar month="2025-10" />
     */
    public function mount(string $month = '2025-05'): void
    {
        $monthObj       = Carbon::parse($month . '-01');
        $this->startDate = $monthObj->copy()->startOfMonth();
        $this->endDate   = $monthObj->copy()->endOfMonth();

        // build the header day list
        $this->days = collect();
        for ($d = $this->startDate->copy(); $d->lte($this->endDate); $d->addDay()) {
            $this->days->push($d->copy());
        }
    }

    public function render()
    {
        $windowStart = $this->startDate->copy()->startOfDay();
        $windowEnd   = $this->endDate->copy()->endOfDay();
        $daysCount   = $this->days->count();

        /* ───── Pull bookings that touch the calendar window ───── */
        $bookings = Booking::with([
                'tour:id,title',      // only need the title column
                'guest:id,full_name', // only need the name column
            ])
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
                           : $start;           // single-day booking fallback

                /* clip to visible window so long events don’t overflow */
                if ($start->lt($windowStart)) $start = $windowStart->copy();
                if ($end  ->gt($windowEnd))   $end   = $windowEnd->copy();

                $b->start_index = $windowStart->diffInDays($start);          // 0-based
                $b->span_days   = max(1, $start->diffInDays($end) + 1);      // >= 1
                $b->left_pct    = ($b->start_index / $daysCount) * 100;      // CSS %
                $b->width_pct   = ($b->span_days   / $daysCount) * 100;      // CSS %

                // label shown inside bar
                $b->bar_label   = $b->guest?->full_name
                                 ?? $b->group_name
                                 ?? "Booking #{$b->id}";

                // simple colour rule by payment status (adjust as you like)
                $b->colour      = $b->payment_status === 'paid'
                                  ? '#16a34a' // green-500
                                  : '#f97316';// orange-500

                return $b;
            });

        /* ───── Group by Tour title so Blade can loop rows ───── */
        $rows = $bookings->groupBy(
            fn ($b) => $b->tour?->title ?? 'Unknown Tour'
        );

        return view('livewire.tour-calendar', [
            'startDate' => $this->startDate, // for the heading
            'days'      => $this->days,
            'rows'      => $rows,
        ]);
    }
}