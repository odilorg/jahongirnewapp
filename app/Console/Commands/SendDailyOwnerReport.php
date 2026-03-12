<?php

namespace App\Console\Commands;

use App\Models\Beds24Booking;
use App\Models\Beds24BookingChange;
use App\Services\OwnerAlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SendDailyOwnerReport extends Command
{
    protected $signature   = 'beds24:daily-report';
    protected $description = 'Send the daily booking summary to the hotel owner via Telegram';

    public function __construct(protected OwnerAlertService $alertService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            return $this->generateReport();
        } catch (\Throwable $e) {
            Log::error('Daily owner report failed', ['error' => $e->getMessage()]);
            $this->error("Report failed: {$e->getMessage()}");
            return self::FAILURE;
        }
    }

    private function generateReport(): int
    {
        $this->info('Generating daily Beds24 owner report...');

        $today     = now('Asia/Tashkent')->toDateString();
        $tomorrow  = now('Asia/Tashkent')->addDay()->toDateString();
        $yesterday = now('Asia/Tashkent')->subDay()->toDateString();

        $propertyIds = ['41097', '172793'];
        $stats = [];

        foreach ($propertyIds as $propertyId) {
            // New bookings created today
            $newBookings = Beds24Booking::forProperty($propertyId)
                ->whereDate('created_at', $today)
                ->where('booking_status', '!=', 'cancelled')
                ->count();

            // Cancellations today
            $cancellations = Beds24Booking::forProperty($propertyId)
                ->whereDate('cancelled_at', $today)
                ->count();

            // Modifications today (from changes table)
            $modifications = Beds24BookingChange::where('change_type', 'modified')
                ->whereDate('detected_at', $today)
                ->whereIn('beds24_booking_id', function ($q) use ($propertyId) {
                    $q->select('beds24_booking_id')
                        ->from('beds24_bookings')
                        ->where('property_id', $propertyId);
                })
                ->count();

            // Current guests (checked in today or before, not yet checked out)
            $currentGuests = Beds24Booking::forProperty($propertyId)
                ->where('booking_status', 'confirmed')
                ->where('arrival_date', '<=', $today)
                ->where('departure_date', '>', $today)
                ->count();

            // Arrivals tomorrow
            $arrivalsTomorrow = Beds24Booking::forProperty($propertyId)
                ->where('booking_status', 'confirmed')
                ->whereDate('arrival_date', $tomorrow)
                ->count();

            // Departures tomorrow
            $departuresTomorrow = Beds24Booking::forProperty($propertyId)
                ->where('booking_status', 'confirmed')
                ->whereDate('departure_date', $tomorrow)
                ->count();

            // Revenue today: sum of payments received today (from Beds24BookingChange)
            // This tracks actual payment events, not booking creation dates
            $paymentChanges = Beds24BookingChange::where('change_type', 'payment_updated')
                ->whereDate('detected_at', $today)
                ->whereIn('beds24_booking_id', function ($q) use ($propertyId) {
                    $q->select('beds24_booking_id')
                        ->from('beds24_bookings')
                        ->where('property_id', $propertyId);
                })
                ->get();

            $revenueToday = 0;
            foreach ($paymentChanges as $pc) {
                $oldBal = (float) ($pc->old_data['invoice_balance'] ?? 0);
                $newBal = (float) ($pc->new_data['invoice_balance'] ?? 0);
                if ($newBal < $oldBal) {
                    $revenueToday += ($oldBal - $newBal);
                }
            }

            // Also count new bookings that arrived pre-paid today
            $prePaidToday = Beds24Booking::forProperty($propertyId)
                ->whereDate('created_at', $today)
                ->where('booking_status', '!=', 'cancelled')
                ->where('invoice_balance', '<=', 0)
                ->where('total_amount', '>', 0)
                ->sum('total_amount');
            $revenueToday += (float) $prePaidToday;

            $primaryCurrency = Beds24Booking::forProperty($propertyId)
                ->where('booking_status', '!=', 'cancelled')
                ->where('arrival_date', '<=', $today)
                ->where('departure_date', '>=', $today)
                ->groupBy('currency')
                ->orderByRaw('COUNT(*) DESC')
                ->value('currency') ?? 'USD';

            $stats[$propertyId] = [
                'new_bookings'        => $newBookings,
                'cancellations'       => $cancellations,
                'modifications'       => $modifications,
                'current_guests'      => $currentGuests,
                'arrivals_tomorrow'   => $arrivalsTomorrow,
                'departures_tomorrow' => $departuresTomorrow,
                'revenue_today'       => number_format((float) $revenueToday, 2),
                'currency'            => $primaryCurrency,
            ];
        }

        // Global unpaid count across both properties
        $stats['unpaid_count'] = Beds24Booking::withUnpaidBalance()
            ->where('booking_status', 'confirmed')
            ->count();

        $this->alertService->sendDailySummary($stats);

        $this->info('Daily report sent successfully.');

        return self::SUCCESS;
    }
}
