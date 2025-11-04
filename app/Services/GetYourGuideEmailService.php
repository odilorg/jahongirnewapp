<?php

namespace AppServices;

use WebklexPHPIMAPClientManager;
use WebklexPHPIMAPClient;
use WebklexPHPIMAPMessage;
use IlluminateSupportCollection;
use IlluminateSupportFacadesLog;
use CarbonCarbon;

class GetYourGuideEmailService
{
    protected ?Client $client = null;

    public function __construct(
        protected ClientManager $clientManager
    ) {}

    /**
     * Establish IMAP connection
     */
    public function connect(): Client
    {
        if ($this->client && $this->isConnected()) {
            return $this->client;
        }

        try {
            $this->client = $this->clientManager->make([
                'host' => config('getyourguide.imap.host'),
                'port' => config('getyourguide.imap.port'),
                'encryption' => config('getyourguide.imap.encryption'),
                'validate_cert' => config('getyourguide.imap.validate_cert'),
                'username' => config('getyourguide.imap.username'),
                'password' => config('getyourguide.imap.password'),
                'protocol' => config('getyourguide.imap.protocol'),
            ]);

            $this->client->connect();

            Log::info('GetYourGuide Email Service: Connected to IMAP successfully');

            return $this->client;

        } catch (Exception $e) {
            Log::error('GetYourGuide Email Service: Connection failed', [
                'error' => $e->getMessage(),
                'host' => config('getyourguide.imap.host'),
            ]);
            throw $e;
        }
    }

    /**
     * Disconnect from IMAP server
     */
    public function disconnect(): void
    {
        if ($this->client) {
            try {
                $this->client->disconnect();
                $this->client = null;
                Log::debug('GetYourGuide Email Service: Disconnected from IMAP');
            } catch (Exception $e) {
                Log::warning('GetYourGuide Email Service: Disconnect error', [
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Check if connected to IMAP
     */
    public function isConnected(): bool
    {
        try {
            return $this->client && $this->client->getConnection() && $this->client->getConnection()->isConnected();
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Fetch emails from last N days
     */
    public function fetchNewEmails(int $days = 1, int $limit = 50): Collection
    {
        $this->connect();

        $folder = $this->client->getFolder(config('getyourguide.email.folder'));

        $messages = $folder->messages()
            ->since(now()->subDays($days))
            ->limit($limit)
            ->get();

        Log::info('GetYourGuide Email Service: Fetched emails', [
            'count' => $messages->count(),
            'days' => $days,
        ]);

        return $messages;
    }

    /**
     * Fetch emails since specific date
     */
    public function fetchEmailsSince(DateTime $since, int $limit = 50): Collection
    {
        $this->connect();

        $folder = $this->client->getFolder(config('getyourguide.email.folder'));

        $messages = $folder->messages()
            ->since($since)
            ->limit($limit)
            ->get();

        Log::info('GetYourGuide Email Service: Fetched emails since date', [
            'count' => $messages->count(),
            'since' => $since->format('Y-m-d H:i:s'),
        ]);

        return $messages;
    }

    /**
     * Filter collection to only GetYourGuide emails
     */
    public function filterGetYourGuideEmails(Collection $messages): Collection
    {
        return $messages->filter(function (Message $message) {
            return $this->isGetYourGuideEmail($message);
        });
    }

    /**
     * Check if email is from GetYourGuide
     */
    protected function isGetYourGuideEmail(Message $message): bool
    {
        $from = $message->getFrom()[0]->mail ?? '';
        $subject = $message->getSubject();
        $body = $message->getTextBody();

        // Check direct sender patterns
        foreach (config('getyourguide.email.from_patterns') as $pattern) {
            if (stripos($from, $pattern) !== false) {
                return true;
            }
        }

        // Check subject patterns
        foreach (config('getyourguide.email.subject_patterns') as $pattern) {
            if (stripos($subject, $pattern) !== false) {
                return true;
            }
        }

        // Check for forwarded GetYourGuide emails
        if ($this->isForwardedGetYourGuideEmail($message)) {
            return true;
        }

        return false;
    }

    /**
     * Check if email is forwarded from GetYourGuide
     */
    protected function isForwardedGetYourGuideEmail(Message $message): bool
    {
        $subject = $message->getSubject();
        $body = $message->getTextBody();

        // Check if subject contains "Fwd:" and GetYourGuide patterns
        if (stripos($subject, 'fwd:') !== false || stripos($subject, 'forwarded') !== false) {
            // Look for GetYourGuide in forwarded content
            if (stripos($body, 'getyourguide') !== false ||
                stripos($body, 'notification.getyourguide.com') !== false ||
                stripos($body, 'GYGG') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract email data for processing
     */
    public function extractEmailData(Message $message): array
    {
        $textBody = $message->getTextBody();
        $htmlBody = $message->getHTMLBody();

        // Check if forwarded and extract original content
        $isForwarded = stripos($message->getSubject(), 'fwd:') !== false;
        if ($isForwarded) {
            $extractedContent = $this->extractForwardedContent($textBody);
            if ($extractedContent) {
                $textBody = $extractedContent;
            }
        }

        return [
            'message_id' => $message->getMessageId(),
            'from' => $message->getFrom()[0]->mail ?? 'unknown',
            'subject' => $message->getSubject(),
            'date' => $message->getDate(),
            'body' => $this->cleanEmailBody($textBody),
            'html' => $htmlBody,
            'is_forwarded' => $isForwarded,
        ];
    }

    /**
     * Clean email body from tracking links and noise
     */
    protected function cleanEmailBody(string $body): string
    {
        // Remove excessive whitespace
        $body = preg_replace('/n{3,}/', "nn", $body);

        // Remove tracking URLs (GetYourGuide uses SendGrid tracking)
        $body = preg_replace('/https://ud+.ct.sendgrid.net[^s]+/', '[LINK]', $body);

        // Remove image placeholders
        $body = preg_replace('/[image:[^]]+]/', '', $body);

        // Trim
        $body = trim($body);

        return $body;
    }

    /**
     * Extract forwarded message content
     */
    protected function extractForwardedContent(string $body): ?string
    {
        // Look for "Forwarded message" marker
        $patterns = [
            '/---------- Forwarded message ---------(.+)/s',
            '/Begin forwarded message:(.+)/s',
            '/From:.*?getyourguide(.+)/si',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $body, $matches)) {
                return trim($matches[1]);
            }
        }

        return null;
    }

    /**
     * Mark email as read
     */
    public function markAsRead(Message $message): void
    {
        try {
            $message->setFlag('Seen');
            Log::debug('GetYourGuide Email Service: Marked email as read', [
                'message_id' => $message->getMessageId(),
            ]);
        } catch (Exception $e) {
            Log::warning('GetYourGuide Email Service: Failed to mark as read', [
                'message_id' => $message->getMessageId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Add custom flag to email
     */
    public function flagEmail(Message $message, string $flag = 'GYGG_Processed'): void
    {
        try {
            $message->setFlag($flag);
            Log::debug('GetYourGuide Email Service: Flagged email', [
                'message_id' => $message->getMessageId(),
                'flag' => $flag,
            ]);
        } catch (Exception $e) {
            Log::warning('GetYourGuide Email Service: Failed to flag email', [
                'message_id' => $message->getMessageId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
