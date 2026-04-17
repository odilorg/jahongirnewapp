<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DailyRecapMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public array $data, public string $baseUrl = '') {}

    public function envelope(): Envelope
    {
        $needs = count($this->data['needs_action'] ?? []);
        $count = $this->data['total_bookings'] ?? 0;

        $subjectParts = ["🌙 Tomorrow · {$this->data['date_label']}"];
        if ($count > 0) {
            $subjectParts[] = "{$count} tour" . ($count === 1 ? '' : 's');
        }
        if ($needs > 0) {
            $subjectParts[] = "⚠ {$needs} need action";
        }

        return new Envelope(
            subject: implode(' · ', $subjectParts),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.daily-recap',
            with: [
                'd'       => $this->data,
                'baseUrl' => rtrim($this->baseUrl, '/'),
            ],
        );
    }
}
