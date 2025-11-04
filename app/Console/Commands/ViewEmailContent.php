<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Webklex\PHPIMAP\ClientManager;

class ViewEmailContent extends Command
{
    protected $signature = 'email:view {subject}';
    protected $description = 'View email content by subject keyword';

    public function handle()
    {
        $searchSubject = $this->argument('subject');
        $this->info("Searching for emails with: {$searchSubject}");

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
            $inbox = $client->getFolder('INBOX');

            $messages = $inbox->messages()
                ->since(now()->subDays(1))
                ->get();

            foreach ($messages as $message) {
                $subject = $message->getSubject();
                
                if (stripos($subject, $searchSubject) !== false) {
                    $this->info("\n" . str_repeat('=', 80));
                    $this->info("Found Email:");
                    $this->info("Subject: " . $subject);
                    $this->info("From: " . ($message->getFrom()[0]->mail ?? 'Unknown'));
                    $this->info("Date: " . $message->getDate());
                    $this->info("Message ID: " . $message->getMessageId());
                    $this->info(str_repeat('=', 80));
                    
                    $this->newLine();
                    $this->info("Plain Text Body:");
                    $this->info(str_repeat('-', 80));
                    $body = $message->getTextBody();
                    $this->line($body);
                    $this->info(str_repeat('-', 80));
                    
                    $this->newLine();
                    $this->info("HTML Body Preview:");
                    $this->info(str_repeat('-', 80));
                    $html = $message->getHTMLBody();
                    if ($html) {
                        // Show first 1000 chars of HTML
                        $this->line(substr($html, 0, 1000) . '...');
                    } else {
                        $this->warn('No HTML body');
                    }
                    $this->info(str_repeat('-', 80));
                    
                    break;
                }
            }

            $client->disconnect();
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
