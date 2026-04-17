<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\InquiryReminder;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InquiryReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public InquiryReminder $reminder) {}

    public function envelope(): Envelope
    {
        $inquiry = $this->reminder->bookingInquiry;

        return new Envelope(
            subject: '⏰ Reminder — ' . ($inquiry?->reference ?? 'Booking'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.inquiry-reminder',
            with: [
                'reminder' => $this->reminder,
                'inquiry'  => $this->reminder->bookingInquiry,
            ],
        );
    }
}
