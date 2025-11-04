<?php

namespace App\Jobs;

use App\Models\GetYourGuideBooking;
use App\Models\EmailProcessingLog;
use App\Services\GetYourGuideDataExtractorService;
use App\Services\GetYourGuideNotificationService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ProcessGetYourGuideEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 120;
    public $backoff = [60, 300]; // 1min, 5min
    public $failOnTimeout = true;

    /**
     * Create a new job instance
     */
    public function __construct(
        public string $messageId,
        public string $emailSubject,
        public string $emailBody,
        public string $emailFrom,
        public Carbon $emailDate,
        public bool $isForwarded = false
    ) {}

    /**
     * Execute the job
     */
    public function handle(
        GetYourGuideDataExtractorService $extractor,
        GetYourGuideNotificationService $notifier
    ): void {
        $startTime = microtime(true);

        Log::info('GetYourGuide: Processing email', [
            'message_id' => $this->messageId,
            'subject' => $this->emailSubject,
            'from' => $this->emailFrom,
            'attempt' => $this->attempts(),
        ]);

        try {
            // Check if already processed (duplicate prevention)
            if ($this->isDuplicate()) {
                Log::info('GetYourGuide: Email already processed, skipping', [
                    'message_id' => $this->messageId,
                ]);
                return;
            }

            // Log fetch action
            $this->logAction('fetched', 'success', [
                'subject' => $this->emailSubject,
                'from' => $this->emailFrom,
                'is_forwarded' => $this->isForwarded,
            ]);

            // Extract booking data using AI
            $result = $extractor->extractBookingData($this->emailBody, $this->emailSubject);

            if (!$result['success']) {
                throw new \Exception($result['error']);
            }

            $extractedData = $result['data'];
            $processingTime = $result['processing_time_ms'];

            // Log extraction success
            $this->logAction('extracted', 'success', [
                'booking_reference' => $extractedData['booking_reference'] ?? 'unknown',
                'processing_time_ms' => $processingTime,
            ], $processingTime);

            // Store in database
            $booking = $this->storeBooking($extractedData);

            // Log storage success
            $this->logAction('stored', 'success', [
                'booking_id' => $booking->id,
                'booking_reference' => $booking->booking_reference,
            ]);

            // Send Telegram notification
            try {
                $notifier->notifyNewBooking($booking, $extractedData, $processingTime);
            } catch (\Exception $e) {
                // Non-blocking: Log but don't fail job
                Log::warning('GetYourGuide: Notification failed but continuing', [
                    'booking_id' => $booking->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $totalTime = round((microtime(true) - $startTime) * 1000);

            Log::info('GetYourGuide: Email processed successfully', [
                'message_id' => $this->messageId,
                'booking_id' => $booking->id,
                'booking_reference' => $booking->booking_reference,
                'total_time_ms' => $totalTime,
            ]);

        } catch (\Exception $e) {
            $this->handleFailure($e, $notifier);
            throw $e; // Re-throw to trigger retry
        }
    }

    /**
     * Handle job failure after all retries
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('GetYourGuide: Email processing failed permanently', [
            'message_id' => $this->messageId,
            'subject' => $this->emailSubject,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        // Log failure
        $this->logAction('failed', 'error', [
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // Send error notification
        try {
            $notifier = app(GetYourGuideNotificationService::class);
            $notifier->notifyProcessingError(
                emailSubject: $this->emailSubject,
                emailFrom: $this->emailFrom,
                errorMessage: $exception->getMessage(),
                context: [
                    'message_id' => $this->messageId,
                    'attempts' => $this->attempts(),
                    'failed_at' => now()->toDateTimeString(),
                ]
            );
        } catch (\Exception $e) {
            Log::error('GetYourGuide: Failed to send error notification', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if email already processed
     */
    protected function isDuplicate(): bool
    {
        return GetYourGuideBooking::where('email_message_id', $this->messageId)->exists();
    }

    /**
     * Store booking in database
     */
    protected function storeBooking(array $data): GetYourGuideBooking
    {
        return DB::transaction(function () use ($data) {
            $booking = new GetYourGuideBooking();

            // Email tracking
            $booking->email_message_id = $this->messageId;

            // Booking details
            $booking->booking_reference = $data['booking_reference'];
            $booking->booking_date = $data['booking_date'] ?? null;
            $booking->tour_name = $data['tour_name'];
            $booking->tour_date = $data['tour_date'];
            $booking->tour_time = $data['tour_time'] ?? null;
            $booking->duration = $data['duration'] ?? null;

            // Guest information
            $booking->guest_name = $data['guest_name'];
            $booking->guest_email = $data['guest_email'] ?? null;
            $booking->guest_phone = $data['guest_phone'] ?? null;
            $booking->number_of_guests = $data['number_of_guests'] ?? 0;
            $booking->adults = $data['adults'] ?? 0;
            $booking->children = $data['children'] ?? 0;

            // Pickup details
            $booking->pickup_location = $data['pickup_location'] ?? null;
            $booking->pickup_time = $data['pickup_time'] ?? null;
            $booking->special_requirements = $data['special_requirements'] ?? null;

            // Payment
            $booking->total_price = $data['total_price'] ?? 0;
            $booking->currency = $data['currency'] ?? 'USD';
            $booking->payment_status = $data['payment_status'] ?? null;

            // Additional
            $booking->language = $data['language'] ?? null;

            // Processing status
            $booking->processing_status = 'completed';
            $booking->processed_at = now();

            $booking->save();

            return $booking;
        });
    }

    /**
     * Log processing action
     */
    protected function logAction(
        string $action,
        string $status,
        array $details = [],
        ?int $processingTime = null
    ): void {
        try {
            EmailProcessingLog::create([
                'email_message_id' => $this->messageId,
                'action' => $action,
                'status' => $status,
                'details' => $details,
                'processing_time_ms' => $processingTime,
            ]);
        } catch (\Exception $e) {
            Log::warning('GetYourGuide: Failed to create processing log', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle failure during processing
     */
    protected function handleFailure(\Exception $exception, GetYourGuideNotificationService $notifier): void
    {
        Log::error('GetYourGuide: Processing error (will retry)', [
            'message_id' => $this->messageId,
            'subject' => $this->emailSubject,
            'error' => $exception->getMessage(),
            'attempt' => $this->attempts(),
            'max_attempts' => $this->tries,
        ]);

        // Log the error
        $this->logAction('failed', 'error', [
            'error' => $exception->getMessage(),
            'attempt' => $this->attempts(),
            'will_retry' => $this->attempts() < $this->tries,
        ]);

        // If this is the last attempt, send notification
        if ($this->attempts() >= $this->tries) {
            try {
                $notifier->notifyProcessingError(
                    emailSubject: $this->emailSubject,
                    emailFrom: $this->emailFrom,
                    errorMessage: $exception->getMessage(),
                    context: [
                        'message_id' => $this->messageId,
                        'attempts' => $this->attempts(),
                    ]
                );
            } catch (\Exception $e) {
                Log::error('GetYourGuide: Failed to send error notification', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
