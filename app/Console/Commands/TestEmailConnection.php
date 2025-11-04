<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Webklex\PHPIMAP\ClientManager;

class TestEmailConnection extends Command
{
    protected $signature = 'email:test-connection';
    protected $description = 'Test IMAP connection to Zoho Mail';

    public function handle()
    {
        $this->info('Testing IMAP connection to Zoho Mail...');

        $host = env('IMAP_HOST', 'imap.zoho.com');
        $port = env('IMAP_PORT', 993);
        $encryption = env('IMAP_ENCRYPTION', 'ssl');
        $username = env('IMAP_USERNAME');
        $password = env('IMAP_PASSWORD');

        $this->info("Host: $host");
        $this->info("Port: $port");
        $this->info("Username: $username");
        $this->info("Password: " . str_repeat('*', strlen($password)));

        try {
            $cm = new ClientManager();

            $client = $cm->make([
                'host' => $host,
                'port' => $port,
                'encryption' => $encryption,
                'validate_cert' => env('IMAP_VALIDATE_CERT', true),
                'username' => $username,
                'password' => $password,
                'protocol' => env('IMAP_PROTOCOL', 'imap'),
            ]);

            $this->info('Connecting...');
            $client->connect();

            $this->info('✅ Connected successfully!');

            // Get folder list
            $folders = $client->getFolders();
            $this->info("\nAvailable folders:");
            foreach ($folders as $folder) {
                $this->line("  - {$folder->name}");
            }

            // Get INBOX
            $inbox = $client->getFolder('INBOX');
            $this->info("\nChecking INBOX...");

            // Get message count
            $messages = $inbox->messages()->all()->get();
            $this->info("Total messages in INBOX: " . $messages->count());

            // Get recent messages (last 5)
            $recentMessages = $inbox->messages()
                ->since(now()->subDays(7))
                ->limit(5)
                ->get();

            $this->info("\nRecent messages (last 7 days):");
            foreach ($recentMessages as $message) {
                $from = $message->getFrom()[0]->mail ?? 'Unknown';
                $subject = $message->getSubject();
                $date = $message->getDate();

                $this->line("  [$date] From: $from");
                $this->line("  Subject: $subject");
                $this->line("  ---");
            }

            $client->disconnect();
            $this->info("\n✅ Test completed successfully!");

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('❌ Connection failed: ' . $e->getMessage());
            $this->error('Stack trace: ' . $e->getTraceAsString());
            return Command::FAILURE;
        }
    }
}
