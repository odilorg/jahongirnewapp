<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Webklex\PHPIMAP\ClientManager;

class CheckGetYourGuideEmails extends Command
{
    protected $signature = 'email:check-gyg';
    protected $description = 'Check for GetYourGuide emails in inbox';

    public function handle()
    {
        $this->info('Checking for GetYourGuide emails...');

        try {
            $cm = new ClientManager();

            $client = $cm->make([
                'host' => env('IMAP_HOST'),
                'port' => env('IMAP_PORT'),
                'encryption' => env('IMAP_ENCRYPTION'),
                'validate_cert' => env('IMAP_VALIDATE_CERT', true),
                'username' => env('IMAP_USERNAME'),
                'password' => env('IMAP_PASSWORD'),
                'protocol' => env('IMAP_PROTOCOL', 'imap'),
            ]);

            $client->connect();
            $this->info('✅ Connected successfully!');

            // Get INBOX
            $inbox = $client->getFolder('INBOX');

            // Search for GetYourGuide emails
            $this->info("\nSearching for emails from GetYourGuide...");
            
            // Try different possible sender addresses
            $senders = [
                'getyourguide',
                'noreply@mail.getyourguide.com',
                'booking@getyourguide.com',
                '@getyourguide.com'
            ];

            $foundEmails = [];
            
            // Get all messages from last 30 days
            $messages = $inbox->messages()
                ->since(now()->subDays(30))
                ->get();

            $this->info("Total messages in last 30 days: " . $messages->count());

            foreach ($messages as $message) {
                $from = $message->getFrom()[0]->mail ?? 'Unknown';
                $subject = $message->getSubject();
                
                // Check if from GetYourGuide
                if (stripos($from, 'getyourguide') !== false || stripos($subject, 'getyourguide') !== false) {
                    $foundEmails[] = [
                        'from' => $from,
                        'subject' => $subject,
                        'date' => $message->getDate(),
                        'message_id' => $message->getMessageId(),
                    ];
                }
            }

            if (empty($foundEmails)) {
                $this->warn("\n❌ No GetYourGuide emails found in the last 30 days.");
                $this->info("\nShowing all emails instead:");
                
                foreach ($messages as $message) {
                    $from = $message->getFrom()[0]->mail ?? 'Unknown';
                    $subject = $message->getSubject();
                    $date = $message->getDate();
                    
                    $this->line("  [$date]");
                    $this->line("  From: $from");
                    $this->line("  Subject: $subject");
                    $this->line("  ---");
                }
            } else {
                $this->info("\n✅ Found " . count($foundEmails) . " GetYourGuide emails:");
                
                foreach ($foundEmails as $email) {
                    $this->line("  [{$email['date']}]");
                    $this->line("  From: {$email['from']}");
                    $this->line("  Subject: {$email['subject']}");
                    $this->line("  Message ID: {$email['message_id']}");
                    $this->line("  ---");
                }
            }

            $client->disconnect();
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('❌ Error: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
