<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\GetYourGuideEmailService;
use App\Services\GetYourGuideDataExtractorService;

class TestDataExtraction extends Command
{
    protected $signature = 'gyg:test-extraction';
    protected $description = 'Test GetYourGuide data extraction with real email';

    public function handle()
    {
        $this->info('Connecting to email server...');
        
        $emailService = app(GetYourGuideEmailService::class);
        $emailService->connect();
        
        $this->info('Fetching emails from last 30 days...');
        $messages = $emailService->fetchNewEmails(30, 10);
        
        $this->info('Filtering GetYourGuide emails...');
        $gyEmails = $emailService->filterGetYourGuideEmails($messages);
        
        if ($gyEmails->count() === 0) {
            $this->error('No GetYourGuide emails found!');
            return 1;
        }
        
        $this->info('Found ' . $gyEmails->count() . ' GetYourGuide email(s)');
        $this->newLine();
        
        $firstEmail = $gyEmails->first();
        $emailData = $emailService->extractEmailData($firstEmail);
        
        $this->info('Subject: ' . $emailData['subject']);
        $this->info('From: ' . $emailData['from']);
        $this->info('Body length: ' . strlen($emailData['body']) . ' chars');
        $this->newLine();
        
        $this->info('Testing AI extraction...');
        $extractor = app(GetYourGuideDataExtractorService::class);
        $result = $extractor->extractBookingData($emailData['body'], $emailData['subject']);
        
        $this->newLine();
        $this->info('Success: ' . ($result['success'] ? 'YES' : 'NO'));
        $this->info('Processing time: ' . ($result['processing_time_ms'] ?? 0) . 'ms');
        
        if ($result['success']) {
            $this->newLine();
            $this->table(
                ['Field', 'Value'],
                collect($result['data'])->map(fn($v, $k) => [$k, is_null($v) ? 'null' : $v])->values()
            );
        } else {
            $this->error('Error: ' . $result['error']);
            if (isset($result['ai_raw_response'])) {
                $this->newLine();
                $this->warn('AI Response:');
                $this->line($result['ai_raw_response']);
            }
        }
        
        $emailService->disconnect();
        
        return 0;
    }
}
