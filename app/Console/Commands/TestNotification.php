<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\GetYourGuideNotificationService;
use App\Services\GetYourGuideEmailService;
use App\Models\GetYourGuideBooking;
use Carbon\Carbon;

class TestNotification extends Command
{
    protected $signature = 'gyg:test-notification {type=booking : Type of notification (booking, error, summary)}';
    protected $description = 'Test GetYourGuide Telegram notifications';

    public function handle()
    {
        $type = $this->argument('type');

        $this->info("Testing GetYourGuide {$type} notification...");
        $this->newLine();

        // Check configuration
        $enabled = config('getyourguide.notifications.enabled');
        $chatIds = config('getyourguide.notifications.staff_chat_ids');

        if (!$enabled) {
            $this->error('Notifications are disabled in config!');
            $this->info('Set GYG_ENABLE_NOTIFICATIONS=true in .env');
            return 1;
        }

        if (empty($chatIds)) {
            $this->error('No staff chat IDs configured!');
            $this->info('Set GYG_STAFF_TELEGRAM_CHAT_IDS=123456789,987654321 in .env');
            return 1;
        }

        $this->info('Configuration:');
        $this->info('  Enabled: Yes');
        $this->info('  Staff Chat IDs: ' . implode(', ', $chatIds));
        $this->newLine();

        $notificationService = app(GetYourGuideNotificationService::class);

        switch ($type) {
            case 'booking':
                return $this->testBookingNotification($notificationService);

            case 'error':
                return $this->testErrorNotification($notificationService);

            case 'summary':
                return $this->testSummaryNotification($notificationService);

            default:
                $this->error("Unknown notification type: {$type}");
                $this->info('Available types: booking, error, summary');
                return 1;
        }
    }

    protected function testBookingNotification(GetYourGuideNotificationService $service): int
    {
        $this->info('Testing with REAL booking data from database...');
        $this->newLine();

        // Try to get the most recent booking
        $booking = GetYourGuideBooking::latest()->first();

        if (!$booking) {
            $this->warn('No bookings found in database. Creating mock booking...');
            $booking = $this->createMockBooking();
        } else {
            $this->info('Using booking: ' . $booking->booking_reference);
        }

        $extractedData = [
            'booking_reference' => $booking->booking_reference,
            'tour_name' => $booking->tour_name,
            'tour_date' => $booking->tour_date,
            'guest_name' => $booking->guest_name,
            // ... other fields
        ];

        $processingTime = 10570; // ms

        $this->info('Sending notification...');
        $result = $service->notifyNewBooking($booking, $extractedData, $processingTime);

        if ($result) {
            $this->info('✅ Notification sent successfully!');
            $this->info('Check your Telegram to see the message.');
            return 0;
        } else {
            $this->error('❌ Failed to send notification. Check logs.');
            return 1;
        }
    }

    protected function testErrorNotification(GetYourGuideNotificationService $service): int
    {
        $this->info('Sending test error notification...');
        $this->newLine();

        $result = $service->notifyProcessingError(
            emailSubject: 'Fwd: Booking - S374926 - GYGG455V4RN8',
            emailFrom: 'test@example.com',
            errorMessage: 'Failed to extract booking reference from email',
            context: [
                'email_body_length' => 1581,
                'ai_response' => 'Invalid JSON format',
                'attempts' => 3,
            ]
        );

        if ($result) {
            $this->info('✅ Error notification sent successfully!');
            $this->info('Check your Telegram to see the message.');
            return 0;
        } else {
            $this->error('❌ Failed to send notification. Check logs.');
            return 1;
        }
    }

    protected function testSummaryNotification(GetYourGuideNotificationService $service): int
    {
        $this->info('Sending test daily summary notification...');
        $this->newLine();

        $result = $service->sendDailySummary(Carbon::today());

        if ($result) {
            $this->info('✅ Summary notification sent successfully!');
            $this->info('Check your Telegram to see the message.');
            return 0;
        } else {
            $this->error('❌ Failed to send notification. Check logs.');
            return 1;
        }
    }

    protected function createMockBooking(): GetYourGuideBooking
    {
        $booking = new GetYourGuideBooking();
        $booking->email_message_id = 'test-' . time();
        $booking->booking_reference = 'GYGG455V4RN8';
        $booking->booking_date = Carbon::today();
        $booking->tour_name = 'From Samarkand: Shahrisabz Private Day Tour';
        $booking->tour_date = Carbon::tomorrow();
        $booking->tour_time = '08:00';
        $booking->duration = '8 hours';
        $booking->guest_name = 'Nicolas Coriggio';
        $booking->guest_email = 'customer-hf4wxd2mxh7gkxi4@reply.getyourguide.com';
        $booking->guest_phone = '+33783356396';
        $booking->number_of_guests = 1;
        $booking->adults = 1;
        $booking->children = 0;
        $booking->pickup_location = 'MX4H+G7Q, Ulitsa Tashkentskaya 43, 140100, Samarkand';
        $booking->pickup_time = null;
        $booking->special_requirements = null;
        $booking->total_price = 85.00;
        $booking->currency = 'USD';
        $booking->payment_status = 'paid';
        $booking->language = 'French';
        $booking->processing_status = 'completed';

        return $booking;
    }
}
