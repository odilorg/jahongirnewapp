<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BookingInquiry;
use App\Models\InquiryReminder;
use App\Services\Calendar\TourCalendarBuilder;
use Carbon\Carbon;

/**
 * Phase 22 — Daily evening recap for the operator.
 *
 * Produces a tight snapshot of tomorrow's tours + readiness gaps +
 * reminders, for delivery via Telegram at 19:00 Asia/Tashkent.
 * Reuses TourCalendarBuilder::computeReadiness() so the truth source
 * is identical to the dispatch board.
 */
class DailyRecapBuilder
{
    public function __construct(private readonly TourCalendarBuilder $calendar)
    {
    }

    /**
     * Build the structured recap data for tomorrow.
     */
    public function buildForTomorrow(?int $assignedToUserId = null): array
    {
        $tomorrow = Carbon::tomorrow('Asia/Tashkent');
        $weekEnd  = Carbon::today()->addDays(7);

        $bookings = BookingInquiry::query()
            ->whereIn('status', [
                BookingInquiry::STATUS_CONFIRMED,
                BookingInquiry::STATUS_AWAITING_PAYMENT,
            ])
            ->whereDate('travel_date', $tomorrow->toDateString())
            ->when($assignedToUserId, fn ($q) => $q->where('assigned_to_user_id', $assignedToUserId))
            ->with(['driver', 'guide', 'stays.accommodation', 'tourProductDirection'])
            ->orderBy('pickup_time')
            ->get();

        $needsAction = [];
        $ready       = [];
        $totalRev    = 0;

        foreach ($bookings as $inq) {
            $totalRev += (float) ($inq->price_quoted ?? 0);

            $readiness = $this->calendar->computeReadinessPublic($inq);
            $row = [
                'id'            => $inq->id,
                'reference'     => $inq->reference,
                'customer'      => $inq->customer_name,
                'pickup_time'   => $inq->pickup_time,
                'pax'           => (int) $inq->people_adults + (int) ($inq->people_children ?? 0),
                'source'        => $inq->source,
                'tour_type'     => $inq->tour_type,
                'driver_name'   => $inq->driver?->full_name,
                'guide_name'    => $inq->guide?->full_name,
                'accommodations' => $inq->stays->pluck('accommodation.name')->filter()->unique()->values()->all(),
                'chips'         => $readiness['chips'],
                'reasons'       => $readiness['reasons'],
            ];

            if (! empty($readiness['reasons'])) {
                $needsAction[] = $row;
            } else {
                $ready[] = $row;
            }
        }

        $remindersTomorrow = InquiryReminder::query()
            ->where('status', 'pending')
            ->whereBetween('remind_at', [
                $tomorrow->copy()->startOfDay(),
                $tomorrow->copy()->endOfDay(),
            ])
            ->when($assignedToUserId, fn ($q) => $q->where('assigned_to_user_id', $assignedToUserId))
            ->with('bookingInquiry')
            ->orderBy('remind_at')
            ->get();

        $weekAhead = BookingInquiry::query()
            ->whereIn('status', [
                BookingInquiry::STATUS_CONFIRMED,
                BookingInquiry::STATUS_AWAITING_PAYMENT,
            ])
            ->whereBetween('travel_date', [Carbon::today()->toDateString(), $weekEnd->toDateString()])
            ->when($assignedToUserId, fn ($q) => $q->where('assigned_to_user_id', $assignedToUserId))
            ->get(['price_quoted']);

        return [
            'date'             => $tomorrow->toDateString(),
            'date_label'       => $tomorrow->format('D, M j'),
            'total_bookings'   => $bookings->count(),
            'total_revenue'    => $totalRev,
            'needs_action'     => $needsAction,
            'ready'            => $ready,
            'reminders'        => $remindersTomorrow->map(fn ($r) => [
                'time'       => $r->remind_at->format('H:i'),
                'message'    => $r->message,
                'inquiry_id' => $r->booking_inquiry_id,
                'reference'  => $r->bookingInquiry?->reference,
            ])->toArray(),
            'week_bookings'    => $weekAhead->count(),
            'week_revenue'     => (float) $weekAhead->sum('price_quoted'),
        ];
    }

    /**
     * Render the data as a short Telegram-ready message.
     * Uses HTML parse_mode for bold + code formatting.
     */
    public function formatTelegram(array $data, string $adminBaseUrl = ''): string
    {
        $lines = [];
        $lines[] = "🌙 <b>Tomorrow — {$data['date_label']}</b>";
        $lines[] = '';

        if ($data['total_bookings'] === 0) {
            $lines[] = 'No tours tomorrow.';
        } else {
            $lines[] = "{$data['total_bookings']} tour" . ($data['total_bookings'] === 1 ? '' : 's')
                . " · \${$this->money($data['total_revenue'])}";
            $lines[] = '';
        }

        if (! empty($data['needs_action'])) {
            $lines[] = '🚨 <b>Needs Action (' . count($data['needs_action']) . ')</b>';
            foreach ($data['needs_action'] as $b) {
                $time  = $b['pickup_time'] ? mb_substr($b['pickup_time'], 0, 5) : '—';
                $url   = $adminBaseUrl ? " · {$adminBaseUrl}/admin/bookings/{$b['id']}/edit" : '';
                $reasons = implode(', ', $b['reasons']);
                $lines[] = "• <b>{$b['customer']}</b> · {$time} · {$b['pax']}pax";
                $lines[] = "   ⚠ {$reasons}";
                if ($url !== '') $lines[] = "   {$url}";
            }
            $lines[] = '';
        }

        if (! empty($data['ready'])) {
            $lines[] = '✅ <b>Ready (' . count($data['ready']) . ')</b>';
            foreach ($data['ready'] as $b) {
                $time = $b['pickup_time'] ? mb_substr($b['pickup_time'], 0, 5) : '—';
                $lines[] = "• {$b['customer']} · {$time} · {$b['pax']}pax · 🚗 {$b['driver_name']}";
            }
            $lines[] = '';
        }

        if (! empty($data['reminders'])) {
            $lines[] = '⏰ <b>Reminders tomorrow</b>';
            foreach ($data['reminders'] as $r) {
                $lines[] = "• {$r['time']} — {$r['message']}";
            }
            $lines[] = '';
        }

        $lines[] = "📊 Week ahead: {$data['week_bookings']} tours · \${$this->money($data['week_revenue'])}";

        return implode("\n", $lines);
    }

    private function money(float $n): string
    {
        return number_format($n, 0);
    }
}
