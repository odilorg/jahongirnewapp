<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\BookingInquiry;
use Carbon\Carbon;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

/**
 * Guest Outstanding Balances — one view showing all bookings with
 * unpaid balance. The "who owes us money" page (operator-first).
 */
class GuestBalances extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-currency-dollar';
    protected static ?string $navigationLabel = 'Guest Balances';
    protected static ?string $cluster = \App\Filament\Clusters\Money::class;
    protected static ?int    $navigationSort  = 40;

    protected static string $view = 'filament.pages.guest-balances';

    public bool $showSettled = false;

    public function getTitle(): string
    {
        return 'Guest Balances';
    }

    public function toggleShowSettled(): void
    {
        $this->showSettled = ! $this->showSettled;
    }

    protected function getViewData(): array
    {
        $query = BookingInquiry::query()
            ->whereNotNull('price_quoted')
            ->where('price_quoted', '>', 0)
            ->whereIn('status', [
                BookingInquiry::STATUS_CONFIRMED,
                BookingInquiry::STATUS_AWAITING_PAYMENT,
            ])
            ->withSum([
                'guestPayments as received' => fn ($q) => $q->where('status', 'recorded'),
            ], 'amount')
            ->orderBy('travel_date', 'asc');

        $bookings = $query->get()->map(function (BookingInquiry $b) {
            $received    = (float) ($b->received ?? 0);
            $outstanding = (float) $b->price_quoted - $received;
            $age         = $b->travel_date ? Carbon::parse($b->travel_date)->diffInDays(Carbon::today(), false) : null;

            // Negative age = future date, 0 = today, positive = past (overdue)
            $ageColor = match (true) {
                $age === null                  => 'transparent',
                $age > 0 && $outstanding > 0   => '#dc2626', // overdue + unpaid = red
                $age <= -4                     => '#16a34a', // green
                $age <= -1                     => '#ca8a04', // yellow
                default                        => '#dc2626', // red (today or unpaid close)
            };

            $state = match (true) {
                $received <= 0             => 'unpaid',
                $received < (float) $b->price_quoted => 'partial',
                default                    => 'paid',
            };

            return [
                'id'            => $b->id,
                'reference'     => $b->reference,
                'customer'      => $b->customer_name,
                'phone'         => $b->customer_phone,
                'travel_date'   => $b->travel_date,
                'quoted'        => (float) $b->price_quoted,
                'received'      => $received,
                'outstanding'   => $outstanding,
                'age'           => $age,
                'age_color'     => $ageColor,
                'age_label'     => $age === null ? '—' : ($age > 0 ? "Overdue {$age}d" : ($age === 0 ? 'Today' : abs($age) . 'd')),
                'state'         => $state,
                'source'        => $b->source,
                'edit_url'      => \App\Filament\Resources\BookingInquiryResource::getUrl('edit', ['record' => $b->id]),
                'wa_phone'      => preg_replace('/[^0-9]/', '', (string) $b->customer_phone),
                'payment_link'  => $b->payment_link,
            ];
        });

        if (! $this->showSettled) {
            $bookings = $bookings->filter(fn ($r) => $r['outstanding'] > 0.01)->values();
        }

        $unpaid = $bookings->filter(fn ($r) => $r['outstanding'] > 0.01);

        return [
            'bookings'         => $bookings,
            'totalQuoted'      => (float) $unpaid->sum('quoted'),
            'totalReceived'    => (float) $unpaid->sum('received'),
            'totalOutstanding' => (float) $unpaid->sum('outstanding'),
            'unpaidCount'      => $unpaid->count(),
        ];
    }
}
