<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\GetYourGuideEmailService;
use App\Jobs\ProcessGetYourGuideEmail;
use App\Models\GetYourGuideBooking;
use Carbon\Carbon;

class FetchGetYourGuideEmails extends Command
{
    protected $signature = 'gyg:fetch-emails
                            {--days=1 : Number of days to fetch emails from}
                            {--limit=50 : Maximum number of emails to fetch}
                            {--force : Reprocess already processed emails}';

    protected $description = 'Fetch GetYourGuide emails and dispatch processing jobs';

    public function handle()
    {
        $this->info('🎫 Fetching GetYourGuide emails...');
        $this->newLine();

        $days = (int) $this->option('days');
        $limit = (int) $this->option('limit');
        $force = $this->option('force');

        // Connect to email server
        $emailService = app(GetYourGuideEmailService::class);

        try {
            $emailService->connect();
            $this->info('✅ Connected to email server');

            // Fetch emails
            $this->info("📥 Fetching emails from last {$days} day(s)...");
            $allMessages = $emailService->fetchNewEmails($days, $limit);
            $this->info("   Found {$allMessages->count()} total emails");

            // Filter GetYourGuide emails
            $this->info('🔍 Filtering GetYourGuide emails...');
            $gyEmails = $emailService->filterGetYourGuideEmails($allMessages);
            $this->info("   Found {$gyEmails->count()} GetYourGuide emails");

            if ($gyEmails->isEmpty()) {
                $this->warn('⚠️  No GetYourGuide emails found.');
                $emailService->disconnect();
                return 0;
            }

            $this->newLine();

            // Get already processed message IDs
            $processedIds = [];
            if (!$force) {
                $processedIds = GetYourGuideBooking::pluck('email_message_id')->toArray();
            }

            // Process each email
            $stats = [
                'new' => 0,
                'duplicate' => 0,
                'dispatched' => 0,
                'errors' => 0,
            ];

            $progressBar = $this->output->createProgressBar($gyEmails->count());
            $progressBar->start();

            foreach ($gyEmails as $email) {
                try {
                    $emailData = $emailService->extractEmailData($email);
                    $messageId = $emailData['message_id'];

                    // Check if already processed
                    if (!$force && in_array($messageId, $processedIds)) {
                        $stats['duplicate']++;
                        $progressBar->advance();
                        continue;
                    }

                    // Dispatch processing job
                    ProcessGetYourGuideEmail::dispatch(
                        messageId: $messageId,
                        emailSubject: $emailData['subject'],
                        emailBody: $emailData['body'],
                        emailFrom: $emailData['from'],
                        emailDate: Carbon::parse($emailData['date']),
                        isForwarded: $emailData['is_forwarded']
                    );

                    $stats['new']++;
                    $stats['dispatched']++;

                } catch (\Exception $e) {
                    $stats['errors']++;
                    $this->error("Error processing email: " . $e->getMessage());
                }

                $progressBar->advance();
            }

            $progressBar->finish();
            $this->newLine();
            $this->newLine();

            // Display summary
            $this->displaySummary($stats, $gyEmails->count());

            // Disconnect
            $emailService->disconnect();
            $this->info('✅ Disconnected from email server');

            return 0;

        } catch (\Exception $e) {
            $this->error('❌ Error: ' . $e->getMessage());
            $emailService->disconnect();
            return 1;
        }
    }

    /**
     * Display processing summary
     */
    protected function displaySummary(array $stats, int $total): void
    {
        $this->info('📊 Processing Summary');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Emails', $total],
                ['New Emails', $stats['new']],
                ['Duplicates (Skipped)', $stats['duplicate']],
                ['Jobs Dispatched', $stats['dispatched']],
                ['Errors', $stats['errors']],
            ]
        );

        if ($stats['dispatched'] > 0) {
            $this->newLine();
            $this->info("🚀 {$stats['dispatched']} job(s) dispatched to queue");
            $this->info('   Monitor progress with: php artisan queue:listen');
        }

        if ($stats['duplicate'] > 0) {
            $this->newLine();
            $this->comment("ℹ️  {$stats['duplicate']} email(s) already processed (use --force to reprocess)");
        }

        if ($stats['errors'] > 0) {
            $this->newLine();
            $this->warn("⚠️  {$stats['errors']} error(s) occurred. Check logs for details.");
        }
    }
}
